<?php

namespace App\Jobs;

use App\Events\MessageStatusUpdated;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\WhatsAppAccount;
use App\Services\WhatsApp\WhatsAppApiException;
use App\Services\WhatsApp\WhatsAppCloudApiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Delivers one pending outgoing message through the Cloud API.
 *   - Retries temporary errors / rate limits with backoff
 *   - Fails immediately on permanent errors (bad token, 24h window, invalid number…)
 *   - Idempotent: a message that already has a wamid is never sent twice
 */
class SendWhatsAppMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public int $timeout = 180;

    public function __construct(public readonly int $messageId) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 90, 270, 600];
    }

    public function handle(): void
    {
        $message = Message::with(['account', 'attachments', 'contact'])->find($this->messageId);

        if (! $message || $message->wa_message_id || $message->status !== Message::STATUS_PENDING) {
            return;
        }

        $account = $message->account;

        if (! $account->is_active) {
            $this->markFailed($message, 'account_disabled', 'The WhatsApp account was disabled before the message was sent.');

            return;
        }

        $api = WhatsAppCloudApiService::for($account);

        try {
            $response = $this->deliver($api, $message);
        } catch (WhatsAppApiException $e) {
            $this->handleApiError($message, $account, $e);

            return;
        }

        $wamid = $response['messages'][0]['id'] ?? null;

        $message->forceFill([
            'wa_message_id' => $wamid,
            'status' => Message::STATUS_SENT,
            'sent_at' => now(),
            'api_response' => $response,
            'error_code' => null,
            'error_message' => null,
        ])->save();

        MessageStatusUpdated::dispatch($message);
    }

    /**
     * @return array<string, mixed>
     */
    private function deliver(WhatsAppCloudApiService $api, Message $message): array
    {
        $to = $message->recipient;
        $replyTo = $message->context_wa_message_id;

        if ($message->type === 'text') {
            return $api->sendTextMessage($to, (string) $message->body, (bool) ($message->content['preview_url'] ?? false), $replyTo);
        }

        if ($message->type === 'template') {
            $c = $message->content ?? [];

            return $api->sendTemplateMessage($to, (string) $c['name'], (string) $c['language'], $c['components'] ?? []);
        }

        if ($message->type === 'interactive') {
            // Bot menu: reply buttons or list message.
            return $api->sendMessage($to, ['type' => 'interactive', 'interactive' => $message->content['interactive'] ?? []], $replyTo);
        }

        // Media by public link (bot steps) — no upload needed.
        if (! empty($message->content['media_link'])) {
            $media = ['link' => (string) $message->content['media_link']];

            return match ($message->type) {
                'image' => $api->sendImageMessage($to, $media, $message->body, $replyTo),
                'video' => $api->sendVideoMessage($to, $media, $message->body, $replyTo),
                'audio' => $api->sendAudioMessage($to, $media, $replyTo),
                'document' => $api->sendDocumentMessage($to, $media, $message->body, $message->content['filename'] ?? null, $replyTo),
                default => throw new WhatsAppApiException("Unsupported media type {$message->type}"),
            };
        }

        /** @var MessageAttachment|null $attachment */
        $attachment = $message->attachments->first();

        if (! $attachment) {
            throw new WhatsAppApiException('Media file is missing for this message.');
        }

        // Upload once; keep the media id so retries don't upload again.
        if (! $attachment->wa_media_id) {
            $absolute = Storage::disk($attachment->disk)->path((string) $attachment->path);
            $attachment->forceFill([
                'wa_media_id' => $api->uploadMedia($absolute, (string) $attachment->mime_type, $attachment->file_name),
            ])->save();
        }

        $media = ['id' => $attachment->wa_media_id];

        return match ($message->type) {
            'image' => $api->sendImageMessage($to, $media, $message->body, $replyTo),
            'video' => $api->sendVideoMessage($to, $media, $message->body, $replyTo),
            'audio' => $api->sendAudioMessage($to, $media, $replyTo, (bool) ($message->content['voice'] ?? false) && ! ($message->content['voice_fallback'] ?? false)),
            'document' => $api->sendDocumentMessage($to, $media, $message->body, $attachment->file_name, $replyTo),
            default => throw new WhatsAppApiException("Unsupported outgoing type {$message->type}"),
        };
    }

    private function handleApiError(Message $message, WhatsAppAccount $account, WhatsAppApiException $e): void
    {
        Log::warning('WhatsApp send failed', ['message_id' => $message->id] + $e->context());

        if ($e->isRetryable() && $this->attempts() < $this->tries) {
            // Rate limits: wait longer; Meta asks clients to back off.
            $delay = $e->isRateLimit() ? 60 * $this->attempts() : ($this->backoff()[$this->attempts() - 1] ?? 600);
            $message->forceFill(['error_message' => 'Retrying: '.$e->userMessage()])->save();
            $this->release($delay);

            return;
        }

        if ($e->isAuthError()) {
            // Surface broken credentials on the account page.
            $account->forceFill([
                'api_status' => WhatsAppAccount::API_FAILED,
                'api_status_message' => $e->userMessage(),
                'api_checked_at' => now(),
            ])->save();
        }

        $message->forceFill(['api_response' => ['error' => $e->error + ['code' => $e->metaCode]]])->save();
        $this->markFailed($message, (string) ($e->metaCode ?? 'error'), $e->userMessage());
    }

    private function markFailed(Message $message, string $code, string $reason): void
    {
        $message->forceFill([
            'status' => Message::STATUS_FAILED,
            'failed_at' => now(),
            'error_code' => Str::limit($code, 20, ''),
            'error_message' => Str::limit($reason, 1000, ''),
        ])->save();

        MessageStatusUpdated::dispatch($message);
    }

    public function failed(?Throwable $e): void
    {
        $message = Message::find($this->messageId);

        if ($message && $message->status === Message::STATUS_PENDING) {
            $reason = $e instanceof WhatsAppApiException ? $e->userMessage() : 'Sending failed: '.Str::limit((string) $e?->getMessage(), 300);
            $this->markFailed($message, 'job_failed', $reason);
        }
    }
}
