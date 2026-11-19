<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Sending is not allowed by WhatsApp policy or by this app's rules
 * (24h window closed, contact opted out, account disabled, …).
 * The message is user-facing.
 */
class MessagingRestrictionException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'restricted')
    {
        parent::__construct($message);
    }

    public static function windowClosed(): self
    {
        return new self(
            'The 24-hour customer service window is closed. WhatsApp only allows an approved template message until the customer replies.',
            'window_closed',
        );
    }

    public static function accountDisabled(): self
    {
        return new self('This WhatsApp account is disabled. Enable it on the WhatsApp Accounts page to send messages.', 'account_disabled');
    }

    public static function optedOut(): self
    {
        return new self('This contact has opted out of business-initiated messages. You can only reply when they message you.', 'opted_out');
    }

    public static function templateNotApproved(): self
    {
        return new self('Only templates approved by Meta can be sent.', 'template_not_approved');
    }
}
