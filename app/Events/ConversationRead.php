<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsToAccount;
use App\Models\Conversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Someone opened / marked the conversation as read — reset unread badges everywhere.
 */
class ConversationRead implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsToAccount, Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Conversation $conversation) {}

    protected function accountId(): int
    {
        return $this->conversation->whatsapp_account_id;
    }

    public function broadcastAs(): string
    {
        return 'conversation.read';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['conversation_id' => $this->conversation->id, 'unread_count' => (int) $this->conversation->unread_count];
    }
}
