<?php

namespace Tests\Feature;

use App\Models\WhatsAppAccount;
use App\Services\WhatsApp\WhatsAppCloudApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesMeta;
use Tests\TestCase;

class CredentialValidationTest extends TestCase
{
    use FakesMeta, RefreshDatabase;

    public function test_valid_credentials_pass(): void
    {
        $account = WhatsAppAccount::factory()->create();
        $this->fakeMetaValid($account->waba_id, $account->phone_number_id);

        $result = WhatsAppCloudApiService::for($account)->validateCredentials();

        $this->assertTrue($result->ok);
        $this->assertTrue($result->subscribed);
        $this->assertSame('+91 98765 43210', $result->phone['display_phone_number']);
    }

    public function test_requests_use_bearer_token_and_appsecret_proof_but_never_put_the_token_in_the_url(): void
    {
        $account = WhatsAppAccount::factory()->create();
        $this->fakeMetaValid($account->waba_id, $account->phone_number_id);

        WhatsAppCloudApiService::for($account)->getPhoneNumberDetails();

        $expectedProof = hash_hmac('sha256', $account->access_token, $account->app_secret);

        Http::assertSent(function (Request $request) use ($account, $expectedProof) {
            return $request->hasHeader('Authorization', 'Bearer '.$account->access_token)
                && str_starts_with($request->url(), 'https://graph.facebook.com/v26.0/'.$account->phone_number_id)
                && str_contains($request->url(), 'appsecret_proof='.$expectedProof)
                && str_contains($request->url(), 'fields=')
                && ! str_contains($request->url(), $account->access_token);
        });
    }

    public function test_expired_token_fails_with_a_helpful_message(): void
    {
        $account = WhatsAppAccount::factory()->create();
        $this->fakeMetaInvalidToken();

        $result = WhatsAppCloudApiService::for($account)->validateCredentials();

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('access token is invalid or expired', $result->message);
        $this->assertStringContainsString('190', $result->message);
    }

    public function test_phone_number_from_a_different_waba_fails(): void
    {
        $account = WhatsAppAccount::factory()->create();
        Http::fake([
            $this->graph("{$account->waba_id}/phone_numbers*") => Http::response(['data' => [['id' => '999999999']]]),
            $this->graph("{$account->phone_number_id}*") => Http::response(['id' => $account->phone_number_id, 'display_phone_number' => '+1 555']),
        ]);

        $result = WhatsAppCloudApiService::for($account)->validateCredentials();

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('was not found in WABA', $result->message);
    }

    public function test_wrong_app_secret_is_reported(): void
    {
        $account = WhatsAppAccount::factory()->create();
        Http::fake([
            'graph.facebook.com/*' => $this->metaError(100, 'Invalid appsecret_proof provided in the API argument'),
        ]);

        $result = WhatsAppCloudApiService::for($account)->validateCredentials();

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('App Secret does not match', $result->message);
    }

    public function test_network_failure_is_handled(): void
    {
        $account = WhatsAppAccount::factory()->create();
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 6: Could not resolve host'));

        $result = WhatsAppCloudApiService::for($account)->validateCredentials();

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('Network problem', $result->message);
    }
}
