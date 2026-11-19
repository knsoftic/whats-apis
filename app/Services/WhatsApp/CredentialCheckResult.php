<?php

namespace App\Services\WhatsApp;

/**
 * Result of WhatsAppCloudApiService::validateCredentials().
 */
final class CredentialCheckResult
{
    /**
     * @param  array<string, mixed>  $phone  Phone number details from Meta
     * @param  array<int, string>  $warnings
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly array $phone = [],
        public readonly ?bool $subscribed = null,
        public readonly ?string $subscriptionMessage = null,
        public readonly array $warnings = [],
    ) {}

    public static function failed(string $message): self
    {
        return new self(false, $message);
    }
}
