<?php

namespace Tests\Concerns;

use App\Models\WhatsAppAccount;
use Illuminate\Testing\TestResponse;

/**
 * Builds Meta-shaped webhook payloads and posts them with a valid signature.
 */
trait SendsWebhooks
{
    /**
     * @param  array<string, mixed>  $payload
     */
    protected function postWebhook(WhatsAppAccount $account, array $payload, ?string $secret = null, bool $sign = true): TestResponse
    {
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        if ($sign) {
            $headers['HTTP_X_HUB_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', $raw, $secret ?? $account->app_secret);
        }

        return $this->call('POST', "/api/webhooks/whatsapp/{$account->uuid}", [], [], [], $headers, $raw);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    protected function webhookEnvelope(WhatsAppAccount $account, array $value, string $field = 'messages'): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => $account->waba_id,
                'changes' => [[
                    'field' => $field,
                    'value' => array_merge([
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => '15550001111',
                            'phone_number_id' => $account->phone_number_id,
                        ],
                    ], $value),
                ]],
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $message  Meta message object (type, text, image, …)
     * @return array<string, mixed>
     */
    protected function incomingPayload(WhatsAppAccount $account, array $message, string $from = '919876543210', string $profileName = 'Ravi Kumar'): array
    {
        return $this->webhookEnvelope($account, [
            'contacts' => [['profile' => ['name' => $profileName], 'wa_id' => $from]],
            'messages' => [array_merge([
                'from' => $from,
                'id' => 'wamid.'.md5(json_encode($message).$from),
                'timestamp' => (string) now()->timestamp,
            ], $message)],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function textPayload(WhatsAppAccount $account, string $text = 'Hello KN Softic', ?string $wamid = null, string $from = '919876543210'): array
    {
        $message = ['type' => 'text', 'text' => ['body' => $text]];

        if ($wamid) {
            $message['id'] = $wamid;
        }

        return $this->incomingPayload($account, $message, $from);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function statusPayload(WhatsAppAccount $account, string $wamid, string $status, array $extra = [], ?int $timestamp = null): array
    {
        return $this->webhookEnvelope($account, [
            'statuses' => [array_merge([
                'id' => $wamid,
                'status' => $status,
                'timestamp' => (string) ($timestamp ?? now()->timestamp),
                'recipient_id' => '919876543210',
            ], $extra)],
        ]);
    }
}
