<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use App\Models\WhatsAppAccount;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Meta WhatsApp Cloud API webhook endpoint.
 *
 * GET  — subscription verification (hub.mode / hub.verify_token / hub.challenge)
 * POST — signed event delivery (X-Hub-Signature-256 = HMAC-SHA256(raw body, App Secret))
 */
class WhatsAppWebhookController extends Controller
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function verify(Request $request, string $account): Response
    {
        $model = WhatsAppAccount::where('uuid', $account)->first();

        if (! $model) {
            return response('Not found', 404);
        }

        // PHP turns "hub.mode" into "hub_mode" in $_GET — accept both.
        $mode = $request->query('hub_mode', $request->query('hub.mode'));
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $challenge = (string) $request->query('hub_challenge', $request->query('hub.challenge'));

        $valid = $mode === 'subscribe'
            && $token !== ''
            && hash_equals((string) $model->webhook_verify_token, $token)
            && preg_match('/^[A-Za-z0-9_\-]{1,255}$/', $challenge) === 1;

        if (! $valid) {
            Log::warning('WhatsApp webhook verification rejected', ['account' => $model->id, 'mode' => $mode]);

            return response('Forbidden', 403);
        }

        $model->forceFill([
            'webhook_status' => WhatsAppAccount::WEBHOOK_VERIFIED,
            'webhook_verified_at' => now(),
        ])->save();

        $this->logger->log('webhook.verified', "Meta verified the webhook for {$model->name}", $model, accountId: $model->id);

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request, string $account): Response
    {
        $model = WhatsAppAccount::where('uuid', $account)->first();

        if (! $model) {
            return response('Not found', 404);
        }

        $raw = $request->getContent();

        if (! $this->signatureIsValid($raw, (string) $request->header('X-Hub-Signature-256'), (string) $model->app_secret)) {
            Log::warning('WhatsApp webhook rejected: invalid signature', ['account' => $model->id, 'ip' => $request->ip()]);

            return response('Invalid signature', 401);
        }

        $payload = json_decode($raw, true);

        if (! is_array($payload)) {
            return response('Invalid JSON', 400);
        }

        if (($payload['object'] ?? null) !== 'whatsapp_business_account') {
            // Signed by our app but not a WhatsApp event — acknowledge and ignore.
            return response('IGNORED', 200);
        }

        $event = WebhookEvent::createOrFirst(
            ['whatsapp_account_id' => $model->id, 'payload_hash' => hash('sha256', $raw)],
            ['event_type' => self::eventType($payload), 'payload' => $raw, 'status' => WebhookEvent::RECEIVED],
        );

        $updates = ['last_webhook_at' => now()];

        // A correctly signed delivery proves the webhook is configured.
        if (! $model->isWebhookVerified()) {
            $updates += ['webhook_status' => WhatsAppAccount::WEBHOOK_VERIFIED, 'webhook_verified_at' => now()];
        }

        $model->forceFill($updates)->save();

        if (! $event->wasRecentlyCreated) {
            // Meta retried a payload we already have — never process it twice.
            return response('DUPLICATE', 200);
        }

        ProcessWebhookEvent::dispatch($event->id)->onQueue(config('whatsapp.queue'));

        return response('EVENT_RECEIVED', 200);
    }

    public static function signatureIsValid(string $raw, string $header, string $secret): bool
    {
        if ($secret === '' || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $expected = hash_hmac('sha256', $raw, $secret);

        return hash_equals($expected, substr($header, 7));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function eventType(array $payload): string
    {
        $types = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];

                if (! empty($value['messages'])) {
                    $types['messages'] = true;
                }

                if (! empty($value['statuses'])) {
                    $types['statuses'] = true;
                }

                if (($change['field'] ?? 'messages') !== 'messages') {
                    $types[$change['field']] = true;
                }
            }
        }

        return match (count($types)) {
            0 => 'other',
            1 => substr((string) array_key_first($types), 0, 30),
            default => 'mixed',
        };
    }
}
