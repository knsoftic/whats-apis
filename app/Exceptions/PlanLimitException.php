<?php

namespace App\Exceptions;

/**
 * The customer's plan does not allow this action (feature off, limit reached,
 * plan expired). Rendered as HTTP 422 / a flash error like any messaging restriction.
 */
class PlanLimitException extends MessagingRestrictionException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 'plan_limit');
    }
}
