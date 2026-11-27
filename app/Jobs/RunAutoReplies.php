<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\WhatsApp\AutoReplyEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Auto Replies for one incoming message (separate from RunBotFlow).
 * Single attempt — a retry could send the same reply twice.
 */
class RunAutoReplies implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $messageId) {}

    public function handle(AutoReplyEngine $engine): void
    {
        $message = Message::with(['account', 'conversation.contact'])->find($this->messageId);

        if ($message) {
            $engine->handle($message);
        }
    }
}
