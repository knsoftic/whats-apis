<?php

namespace App\Events\Concerns;

use Illuminate\Broadcasting\PrivateChannel;

/**
 * Every real-time event goes to the private channel of ONE WhatsApp account.
 * routes/channels.php only authorises users who can access that account.
 */
trait BroadcastsToAccount
{
    abstract protected function accountId(): int;

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('whatsapp.account.'.$this->accountId())];
    }
}
