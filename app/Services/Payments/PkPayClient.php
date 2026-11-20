<?php

namespace App\Services\Payments;

use App\Support\PlatformSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * PKPay merchant API (https://pkpay.site/docs) — Raast QR, PKR only.
 *
 * Request auth: X-Api-Key, X-Timestamp (unix seconds), X-Signature =
 *   hex HMAC-SHA256( timestamp "\n" METHOD "\n" REQUEST_URI "\n" RAW_BODY , api_secret )
 * POST also needs Idempotency-Key (8–128 chars).
 * Callback auth: X-Timestamp + X-Signature = hex HMAC-SHA256( timestamp "." raw_body , api_secret ).
 */
class PkPayClient
{
    public const MIN_AMOUNT = 1;
    public const MAX_AMOUNT = 500000;

    public function __construct(private readonly PlatformSettings $settings) {}

    public function isEnabled(): bool
    {
        return $this->settings->bool('billing.pkpay_enabled')
            && filled($this->settings->get('billing.pkpay_api_key'))
            && filled($this->settings->get('billing.pkpay_api_secret'));
    }

    private function baseUrl(): string
    {
        return rtrim((string) $this->settings->get('billing.pkpay_base_url'), '/');
    }

    private function secret(): string
    {
        return (string) $this->settings->get('billing.pkpay_api_secret');
    }

    /**
     * POST /payments — returns the "data" object (payment_id, checkout_url, …).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createPayment(array $payload, string $idempotencyKey): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        return $this->send('POST', '/payments', $body, ['Idempotency-Key' => $idempotencyKey]);
    }

    /**
     * GET /payments/{payment_id}
     *
     * @return array<string, mixed>
     */
    public function getPayment(string $paymentId): array
    {
        return $this->send('GET', '/payments/'.rawurlencode($paymentId), '');
    }

    public function signRequest(string $timestamp, string $method, string $requestUri, string $body): string
    {
        return hash_hmac('sha256', $timestamp."\n".strtoupper($method)."\n".$requestUri."\n".$body, $this->secret());
    }

    public function verifyCallback(string $rawBody, ?string $timestamp, ?string $signature): bool
    {
        if (! filled($timestamp) || ! filled($signature) || $this->secret() === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp.'.'.$rawBody, $this->secret()), strtolower(trim($signature)));
    }

    /**
     * @param  array<string, string>  $extraHeaders
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, string $body, array $extraHeaders = []): array
    {
        $url = $this->baseUrl().$path;
        $requestUri = (string) parse_url($url, PHP_URL_PATH);
        $timestamp = (string) time();

        $request = Http::timeout(30)->acceptJson()->withHeaders($extraHeaders + [
            'X-Api-Key' => (string) $this->settings->get('billing.pkpay_api_key'),
            'X-Timestamp' => $timestamp,
            'X-Signature' => $this->signRequest($timestamp, $method, $requestUri, $body),
        ]);

        try {
            /** @var Response $response */
            $response = $method === 'POST'
                ? $request->withBody($body, 'application/json')->post($url)
                : $request->get($url);
        } catch (ConnectionException) {
            throw new PaymentGatewayException('Could not reach PKPay. Please try again in a moment.');
        }

        if ($response->failed()) {
            $code = $response->json('error.code');
            $message = match ($code) {
                'ip_not_allowed' => 'PKPay rejected this server (IP not on the allowlist). Ask the PKPay admin to allow the server IP.',
                'invalid_signature', 'unauthenticated' => 'PKPay rejected the API key / secret. Check Settings → Payments.',
                'timestamp_out_of_range' => 'Server clock is out of sync with PKPay (more than 5 minutes).',
                'amount_out_of_range' => 'Amount must be between PKR 1 and PKR 500,000.',
                'merchant_disabled', 'payin_disabled', 'no_account_available' => 'PKPay payments are currently unavailable. Please try later or choose another method.',
                'rate_limited' => 'Too many payment attempts. Please wait a minute.',
                default => 'PKPay error: '.($response->json('error.message') ?? 'HTTP '.$response->status()),
            };

            throw new PaymentGatewayException($message, is_string($code) ? $code : null);
        }

        return (array) ($response->json('data') ?? []);
    }
}
