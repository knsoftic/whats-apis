<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Error returned by the Meta Graph API (or a network failure reaching it).
 * Never contains the access token.
 */
class WhatsAppApiException extends RuntimeException
{
    /** Errors worth retrying later (throughput / temporary server problems). */
    private const RETRYABLE_CODES = [1, 2, 4, 17, 341, 80007, 130429, 131000, 131016, 131056, 133004];

    /** Token / permission problems — fix the credentials, retrying will not help. */
    private const AUTH_CODES = [0, 10, 102, 190, 200, 210, 3, 33];

    /**
     * @param  array<string, mixed>  $error
     */
    public function __construct(
        string $message,
        public readonly ?int $metaCode = null,
        public readonly ?int $metaSubcode = null,
        public readonly ?string $metaType = null,
        public readonly int $httpStatus = 0,
        public readonly ?string $fbtraceId = null,
        public readonly array $error = [],
        public readonly bool $isConnectionError = false,
    ) {
        parent::__construct($message, $metaCode ?? 0);
    }

    public static function fromResponse(Response $response): self
    {
        $error = (array) ($response->json('error') ?? []);
        $details = $error['error_data']['details'] ?? null;
        $message = trim(($error['message'] ?? 'Meta API request failed').($details ? ' — '.$details : ''));

        return new self(
            message: $message,
            metaCode: isset($error['code']) ? (int) $error['code'] : null,
            metaSubcode: isset($error['error_subcode']) ? (int) $error['error_subcode'] : null,
            metaType: $error['type'] ?? null,
            httpStatus: $response->status(),
            fbtraceId: $error['fbtrace_id'] ?? null,
            error: $error,
        );
    }

    public static function connection(ConnectionException $e): self
    {
        // Strip query strings from the message (they could include appsecret_proof).
        $msg = preg_replace('/\?[^\s"]+/', '?…', $e->getMessage());

        return new self('Could not reach Meta Graph API: '.$msg, httpStatus: 0, isConnectionError: true);
    }

    public function isRateLimit(): bool
    {
        return in_array($this->metaCode, [4, 17, 80007, 130429, 131056, 131048], true) || $this->httpStatus === 429;
    }

    public function isAuthError(): bool
    {
        return in_array($this->metaCode, self::AUTH_CODES, true)
            || ($this->metaCode !== null && $this->metaCode >= 200 && $this->metaCode <= 299)
            || $this->httpStatus === 401;
    }

    /** 131047: more than 24 hours since the customer last replied. */
    public function isReEngagementRequired(): bool
    {
        return $this->metaCode === 131047;
    }

    public function isRetryable(): bool
    {
        return $this->isConnectionError
            || in_array($this->metaCode, self::RETRYABLE_CODES, true)
            || $this->httpStatus >= 500;
    }

    /**
     * Friendly explanation shown to staff.
     */
    public function userMessage(): string
    {
        $hint = match (true) {
            $this->isConnectionError => 'Network problem reaching Meta. Check the server internet connection.',
            $this->metaCode === 190 => 'The access token is invalid or expired. Generate a new (permanent System User) token in Meta Business Settings.',
            $this->metaCode === 100 && str_contains(strtolower($this->getMessage()), 'appsecret_proof') => 'The App Secret does not match the app that issued this access token.',
            $this->metaCode === 100 => 'Meta rejected a parameter. Check the IDs (WABA ID / Phone Number ID) and message content.',
            $this->isAuthError() => 'The token does not have permission for this WhatsApp Business Account (needs whatsapp_business_messaging and whatsapp_business_management).',
            $this->isRateLimit() => 'Meta rate limit reached. The message will be retried automatically; slow down sending.',
            $this->metaCode === 131047 => 'More than 24 hours have passed since the customer last messaged. Send an approved template instead.',
            $this->metaCode === 131030 => 'This recipient is not in the allowed list of your test number. Add it in Meta App Dashboard → WhatsApp → API Setup.',
            $this->metaCode === 131026 => 'Message undeliverable — the number may not be on WhatsApp or has not accepted the latest terms.',
            $this->metaCode === 133010 => 'The phone number is not registered with Cloud API. Register it in WhatsApp Manager.',
            $this->metaCode === 131031 => 'The WhatsApp Business Account is locked or restricted by Meta.',
            $this->metaCode === 368 => 'Temporarily blocked by Meta for policy violations.',
            in_array($this->metaCode, [132000, 132012], true) => 'Template parameters do not match the approved template.',
            $this->metaCode === 132001 => 'Template does not exist in this language, or is not approved.',
            in_array($this->metaCode, [132015, 132016], true) => 'This template is paused or disabled by Meta.',
            default => null,
        };

        $code = $this->metaCode !== null ? " (Meta error {$this->metaCode})" : '';

        return $hint ? "{$hint}{$code}" : $this->getMessage().$code;
    }

    /**
     * Safe context for logs (no token, no request body).
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'meta_code' => $this->metaCode,
            'meta_subcode' => $this->metaSubcode,
            'type' => $this->metaType,
            'http_status' => $this->httpStatus,
            'fbtrace_id' => $this->fbtraceId,
        ];
    }
}
