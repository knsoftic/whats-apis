<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use App\Services\WhatsApp\WebhookProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProcessWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [5, 30, 120, 600];

    public function __construct(public readonly int $webhookEventId) {}

    public function handle(WebhookProcessor $processor): void
    {
        $event = WebhookEvent::with('account')->find($this->webhookEventId);

        if (! $event || $event->status === WebhookEvent::PROCESSED || ! $event->account) {
            return;
        }

        $event->forceFill(['status' => WebhookEvent::PROCESSING, 'attempts' => $event->attempts + 1])->save();

        $processor->process($event);

        $event->forceFill(['status' => WebhookEvent::PROCESSED, 'processed_at' => now(), 'error' => null])->save();
    }

    public function failed(?Throwable $e): void
    {
        WebhookEvent::whereKey($this->webhookEventId)->update([
            'status' => WebhookEvent::FAILED,
            'error' => Str::limit((string) $e?->getMessage(), 1000),
        ]);

        Log::error('WhatsApp webhook event failed permanently', ['webhook_event_id' => $this->webhookEventId, 'error' => $e?->getMessage()]);
    }
}
