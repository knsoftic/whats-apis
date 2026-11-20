<?php

namespace App\Services\Payments;

use RuntimeException;

class PaymentGatewayException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $gatewayCode = null)
    {
        parent::__construct($message);
    }
}
