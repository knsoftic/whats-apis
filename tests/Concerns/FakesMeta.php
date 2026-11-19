<?php

namespace Tests\Concerns;

use App\Models\WhatsAppAccount;
use Illuminate\Support\Facades\Http;

/**
 * Fake Meta Graph API responses. No test ever reaches graph.facebook.com.
 */
trait FakesMeta
{
    protected function graph(string $path = ''): string
    {
        return 'https://graph.facebook.com/v26.0/'.ltrim($path, '/');
    }

    /**
     * Valid credentials: phone exists, belongs to WABA, app subscribed.
     *
     * @param  array<string, mixed>  $extra  Additional URL => response fakes (checked first)
     */
    protected function fakeMetaValid(string $wabaId, string $phoneId, array $extra = [], bool $subscribed = true): void
    {
        Http::fake($extra + [
            $this->graph("{$wabaId}/phone_numbers*") => Http::response([
                'data' => [['id' => $phoneId, 'display_phone_number' => '+91 98765 43210', 'verified_name' => 'KN Softic']],
            ]),
            $this->graph("{$wabaId}/subscribed_apps*") => Http::response([
                'data' => $subscribed ? [['whatsapp_business_api_data' => ['id' => '111', 'name' => 'KN Softic App']]] : [],
            ]),
            $this->graph("{$phoneId}*") => Http::response([
                'id' => $phoneId,
                'display_phone_number' => '+91 98765 43210',
                'verified_name' => 'KN Softic',
                'quality_rating' => 'GREEN',
                'code_verification_status' => 'VERIFIED',
                'platform_type' => 'CLOUD_API',
                'throughput' => ['level' => 'STANDARD'],
                'name_status' => 'APPROVED',
            ]),
        ]);
    }

    protected function fakeMetaInvalidToken(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => [
                    'message' => 'Error validating access token: Session has expired',
                    'type' => 'OAuthException',
                    'code' => 190,
                    'error_subcode' => 463,
                    'fbtrace_id' => 'AbC123',
                ],
            ], 401),
        ]);
    }

    /**
     * Successful message send response.
     */
    protected function metaSendOk(string $wamid = 'wamid.TEST123', string $to = '919876543210'): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response([
            'messaging_product' => 'whatsapp',
            'contacts' => [['input' => $to, 'wa_id' => $to]],
            'messages' => [['id' => $wamid]],
        ]);
    }

    protected function metaError(int $code, string $message = 'Error', int $status = 400): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response(['error' => ['message' => $message, 'type' => 'OAuthException', 'code' => $code, 'fbtrace_id' => 'X']], $status);
    }

    protected function accountPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Sales India',
            'meta_business_id' => '1234567890',
            'waba_id' => '200000000000001',
            'phone_number_id' => '100000000000001',
            'access_token' => 'EAAGtesttoken'.str_repeat('x', 150).'END1',
            'app_secret' => 'abcdef0123456789abcdef0123456789',
            'webhook_verify_token' => 'my-verify-token-123',
            'graph_api_version' => 'v26.0',
            'is_active' => '1',
        ], $overrides);
    }

    protected function connectedAccount(array $attributes = []): WhatsAppAccount
    {
        return WhatsAppAccount::factory()->connected()->create($attributes);
    }
}
