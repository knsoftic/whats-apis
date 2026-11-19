<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsToAccount;
use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Delivery status changed (sent → delivered → read, or failed).
 */
class MessageStatusUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsToAccount, Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message) {}

    protected function accountId(): int
    {
        return $this->message->whatsapp_account_id;
    }

    public function broadcastAs(): string
    {
        return 'message.status';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'wa_message_id' => $this->message->wa_message_id,
            'status' => $this->message->status,
            'error_code' => $this->message->error_code,
            'error_message' => $this->message->error_message,
        ];
    }
}
