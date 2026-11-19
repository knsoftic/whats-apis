<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsToAccount;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A message was created by another agent (outgoing) or changed (media downloaded).
 * Keeps every open chat window in sync.
 */
class MessageUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsToAccount, Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message) {}

    protected function accountId(): int
    {
        return $this->message->whatsapp_account_id;
    }

    public function broadcastAs(): string
    {
        return 'message.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $message = $this->message->fresh(['attachments', 'replyTo', 'sentBy', 'conversation.contact']) ?? $this->message;

        return [
            'message' => (new MessageResource($message))->resolve(),
            'conversation' => (new ConversationResource($message->conversation))->resolve(),
        ];
    }
}
