<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\WhatsApp\WhatsAppApiException;
use App\Services\WhatsApp\WhatsAppCloudApiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Tells WhatsApp the business has read an incoming message (customer sees blue ticks).
 * Marking the latest message as read marks all earlier ones too.
 */
class SendReadReceipt implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    public function __construct(public readonly int $messageId) {}

    public function handle(): void
    {
        $message = Message::with('account')->find($this->messageId);

        if (! $message || $message->direction !== Message::IN || ! $message->wa_message_id || $message->read_at) {
            return;
        }

        // Meta only accepts read receipts for recent messages (≈30 days).
        if (($message->wa_timestamp ?? $message->created_at)->lt(now()->subDays(29))) {
            return;
        }

        try {
            WhatsAppCloudApiService::for($message->account)->markMessageAsRead($message->wa_message_id);
        } catch (WhatsAppApiException $e) {
            if ($e->isRetryable()) {
                throw $e;
            }

            Log::info('Read receipt not accepted by Meta', ['message_id' => $message->id] + $e->context());

            return;
        }

        Message::where('conversation_id', $message->conversation_id)
            ->where('direction', Message::IN)
            ->where('id', '<=', $message->id)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'status' => Message::STATUS_READ]);
    }
}
