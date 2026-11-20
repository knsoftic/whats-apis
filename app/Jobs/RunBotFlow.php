<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\WhatsApp\BotEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs bot flows for one incoming message. Single attempt — a retry could
 * send the same automatic replies twice.
 */
class RunBotFlow implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly int $messageId) {}

    public function handle(BotEngine $engine): void
    {
        $message = Message::with(['account', 'conversation.contact'])->find($this->messageId);

        if ($message) {
            $engine->handle($message);
        }
    }
}
