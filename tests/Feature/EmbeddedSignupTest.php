<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\WhatsAppAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresMetaPlatform;
use Tests\Concerns\FakesMeta;
use Tests\TestCase;

class EmbeddedSignupTest extends TestCase
{
    use ConfiguresMetaPlatform, FakesMeta, RefreshDatabase;

    private string $waba = '200000000000077';

    private string $phone = '100000000000077';

    protected function setUp(): void
    {
        parent::setUp();
        Setting::flushCache();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function fakeMetaSignup(array $overrides = [], array $grantedWabas = ['200000000000077'], bool $registerOk = true): void
    {
        Http::fake($overrides + [
            $this->graph('oauth/access_token*') => Http::response(['access_token' => 'EAAGbusinessTOKEN'.str_repeat('b', 60), 'token_type' => 'bearer']),
            $this->graph('debug_token*') => Http::response(['data' => ['is_valid' => true, 'granular_scopes' => [
                ['scope' => 'whatsapp_business_management', 'target_ids' => $grantedWabas],
                ['scope' => 'whatsapp_business_messaging', 'target_ids' => $grantedWabas],
            ]]]),
            $this->graph("{$this->phone}/register*") => $registerOk
                ? Http::response(['success' => true])
                : $this->metaError(133005, 'Two step verification PIN Mismatch'),
            $this->graph("{$this->waba}/subscribed_apps*") => fn (Request $r) => $r->method() === 'POST'
                ? Http::response(['success' => true])
                : Http::response(['data' => [['whatsapp_business_api_data' => ['id' => '111222333444', 'name' => 'KN Softic Platform']]]]),
            $this->graph("{$this->waba}/phone_numbers*") => Http::response(['data' => [['id' => $this->phone, 'display_phone_number' => '+91 99887 76655']]]),
            $this->graph("{$this->phone}*") => Http::response([
                'id' => $this->phone, 'display_phone_number' => '+91 99887 76655', 'verified_name' => 'Acme Retail',
                'quality_rating' => 'GREEN', 'code_verification_status' => 'VERIFIED',
            ]),
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'AQD-one-time-code',
            'event' => 'FINISH',
            'waba_id' => $this->waba,
            'phone_number_id' => $this->phone,
            'business_id' => '300000000000077',
            'pin' => '123456',
        ], $overrides);
    }

    public function test_embedded_signup_creates_a_fully_configured_account(): void
    {
        $this->configurePlatform(webhookVerified: true);
        $this->fakeMetaSignup();

        $this->actingAs($this->admin())->postJson('/whatsapp-accounts/embedded-signup', $this->payload())
            ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('created', true);

        $account = WhatsAppAccount::firstOrFail();
        $this->assertSame(WhatsAppAccount::ONBOARD_EMBEDDED, $account->onboarding_method);
        $this->assertSame($this->waba, $account->waba_id);
        $this->assertSame($this->phone, $account->phone_number_id);
        $this->assertSame('300000000000077', $account->meta_business_id);
        $this->assertStringStartsWith('EAAGbusinessTOKEN', $account->access_token);
        $this->assertSame($this->platformSecret, $account->app_secret);
        $this->assertSame('registered', $account->registration_status);
        $this->assertSame(WhatsAppAccount::API_CONNECTED, $account->api_status);
        $this->assertSame(WhatsAppAccount::SUB_SUBSCRIBED, $account->subscription_status);
        $this->assertSame(WhatsAppAccount::WEBHOOK_VERIFIED, $account->webhook_status);
        $this->assertSame('Acme Retail', $account->name);

        // Code exchanged server-side with the platform app credentials.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth/access_token')
            && str_contains($r->url(), 'client_id=111222333444')
            && str_contains($r->url(), 'code=AQD-one-time-code'));
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), "{$this->waba}/subscribed_apps"));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), "{$this->phone}/register") && $r['pin'] === '123456' && $r['messaging_product'] === 'whatsapp');
        $this->assertDatabaseHas('activity_logs', ['action' => 'account.embedded_signup']);
    }

    public function test_waba_not_granted_to_the_token_is_rejected(): void
    {
        $this->configurePlatform();
        $this->fakeMetaSignup(grantedWabas: ['999999999999999']);

        $this->actingAs($this->admin())->postJson('/whatsapp-accounts/embedded-signup', $this->payload())
            ->assertStatus(422)->assertJsonFragment(['message' => 'The WhatsApp Business Account returned by the popup was not shared with this app. Please run “Connect with Meta” again.']);

        $this->assertDatabaseCount('whatsapp_accounts', 0);
    }

    public function test_expired_code_gives_a_clear_error(): void
    {
        $this->configurePlatform();
        Http::fake([$this->graph('oauth/access_token*') => $this->metaError(100, 'This authorization code has expired.')]);

        $this->actingAs($this->admin())->postJson('/whatsapp-accounts/embedded-signup', $this->payload())
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'valid for 5 minutes'));
    }

    public function test_requires_platform_configuration(): void
    {
        Http::fake();

        $this->actingAs($this->admin())->postJson('/whatsapp-accounts/embedded-signup', $this->payload())
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'not configured'));

        $this->actingAs($this->admin())->get('/whatsapp-accounts/connect')->assertOk()->assertSee('Embedded Signup is not set up yet');
        Http::assertNothingSent();
    }

    public function test_connect_page_loads_the_sdk_config_without_secrets(): void
    {
        $this->configurePlatform();

        $html = $this->actingAs($this->admin())->get('/whatsapp-accounts/connect')->assertOk()->assertSee('Connect WhatsApp with Facebook')->getContent();

        $this->assertStringContainsString('111222333444', $html);   // App ID is public
        $this->assertStringContainsString('555666777888', $html);   // configuration id is public
        $this->assertStringNotContainsString($this->platformSecret, $html);
    }

    public function test_agent_cannot_use_embedded_signup(): void
    {
        $this->configurePlatform();
        Http::fake();

        $this->actingAs($this->agent())->get('/whatsapp-accounts/connect')->assertForbidden();
        $this->actingAs($this->agent())->postJson('/whatsapp-accounts/embedded-signup', $this->payload())->assertForbidden();
    }

    public function test_whatsapp_business_app_number_is_not_registered_again(): void
    {
        $this->configurePlatform();
        $this->fakeMetaSignup();

        $this->actingAs($this->admin())->postJson('/whatsapp-accounts/embedded-signup', $this->payload(['event' => 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING']))->assertOk();

        $this->assertSame('coexistence', WhatsAppAccount::firstOrFail()->registration_status);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/register'));
    }

    public function test_failed_registration_still_saves_account_and_can_be_retried(): void
    {
        $this->configurePlatform();
        // First /register call fails (wrong PIN), the retry succeeds.
        $this->fakeMetaSignup([
            $this->graph("{$this->phone}/register*") => Http::sequence()
                ->pushResponse($this->metaError(133005, 'Two step verification PIN Mismatch'))
                ->push(['success' => true]),
        ]);

        $this->actingAs($admin = $this->admin())->postJson('/whatsapp-accounts/embedded-signup', $this->payload())
            ->assertOk()->assertJsonPath('warnings.0', fn ($w) => str_contains($w, 'Phone registration'));

        $account = WhatsAppAccount::firstOrFail();
        $this->assertSame('failed', $account->registration_status);

        $this->actingAs($admin)->post("/whatsapp-accounts/{$account->id}/register", ['pin' => '654321'])->assertSessionHas('success');
        $this->assertSame('registered', $account->fresh()->registration_status);
    }

    public function test_reconnecting_the_same_number_updates_instead_of_duplicating(): void
    {
        $this->configurePlatform();
        $existing = WhatsAppAccount::factory()->create(['phone_number_id' => $this->phone, 'name' => 'Old name', 'access_token' => 'EAAGoldtoken'.str_repeat('o', 40)]);
        $this->fakeMetaSignup();

        $this->actingAs($this->admin())->postJson('/whatsapp-accounts/embedded-signup', $this->payload())->assertOk()->assertJsonPath('created', false);

        $this->assertDatabaseCount('whatsapp_accounts', 1);
        $existing->refresh();
        $this->assertStringStartsWith('EAAGbusinessTOKEN', $existing->access_token);
        $this->assertSame('Old name', $existing->name);
        $this->assertSame(WhatsAppAccount::ONBOARD_EMBEDDED, $existing->onboarding_method);
    }

    public function test_missing_phone_id_falls_back_to_the_wabas_first_number(): void
    {
        $this->configurePlatform();
        $this->fakeMetaSignup();

        $this->actingAs($this->admin())->postJson('/whatsapp-accounts/embedded-signup', $this->payload(['phone_number_id' => null, 'waba_id' => null]))->assertOk();

        $this->assertSame($this->phone, WhatsAppAccount::firstOrFail()->phone_number_id);
    }

    public function test_without_popup_ids_the_waba_not_connected_yet_is_used_never_an_old_one(): void
    {
        $this->configurePlatform(webhookVerified: true);
        // An older (e.g. banned) WABA of the same business is already connected here.
        $old = WhatsAppAccount::factory()->create(['waba_id' => '200000000000066', 'phone_number_id' => '100000000000066', 'is_active' => false]);
        $this->fakeMetaSignup(grantedWabas: ['200000000000066', $this->waba]);

        $this->actingAs($this->admin())->postJson('/whatsapp-accounts/embedded-signup', $this->payload(['phone_number_id' => null, 'waba_id' => null]))
            ->assertOk()->assertJsonPath('created', true);

        $new = WhatsAppAccount::whereKeyNot($old->id)->firstOrFail();
        $this->assertSame($this->waba, $new->waba_id);
        $this->assertSame($this->phone, $new->phone_number_id);
        $this->assertFalse($old->fresh()->is_active); // the old account is left alone
    }

    public function test_without_popup_ids_and_several_new_wabas_it_asks_to_retry_instead_of_guessing(): void
    {
        $this->configurePlatform(webhookVerified: true);
        $this->fakeMetaSignup(grantedWabas: ['200000000000055', $this->waba]);

        $this->actingAs($this->admin())->postJson('/whatsapp-accounts/embedded-signup', $this->payload(['phone_number_id' => null, 'waba_id' => null]))
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'did not say which WhatsApp Business Account'));

        $this->assertSame(0, WhatsAppAccount::count());
    }
}
