<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\WhatsAppAccount;
use App\Support\MetaPlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresMetaPlatform;
use Tests\Concerns\FakesMeta;
use Tests\TestCase;

class MetaSettingsTest extends TestCase
{
    use ConfiguresMetaPlatform, FakesMeta, RefreshDatabase;

    private function form(array $overrides = []): array
    {
        return array_merge([
            'app_id' => '111222333444',
            'app_secret' => 'brandnewsecret0123456789abcdef00',
            'es_config_id' => '555666777888',
            'webhook_verify_token' => 'platform-verify-token',
            'graph_version' => 'v26.0',
        ], $overrides);
    }

    public function test_only_super_admin_can_open_meta_settings(): void
    {
        Setting::flushCache();

        $this->actingAs($this->agent())->get('/settings/meta')->assertForbidden();
        $this->actingAs($this->admin())->get('/settings/meta')->assertForbidden();
        $this->actingAs($this->superAdmin())->get('/settings/meta')->assertOk()->assertSee('Meta Tech Provider settings');
    }

    public function test_settings_are_saved_encrypted(): void
    {
        Setting::flushCache();

        $this->actingAs($this->superAdmin())->put('/settings/meta', $this->form())->assertSessionHas('success');

        $raw = DB::table('settings')->where('key', MetaPlatform::APP_SECRET)->value('value');
        $this->assertStringNotContainsString('brandnewsecret', $raw);

        Setting::flushCache();
        $platform = app(MetaPlatform::class);
        $this->assertSame('brandnewsecret0123456789abcdef00', $platform->appSecret());
        $this->assertSame('555666777888', $platform->configId());
        $this->assertTrue($platform->isConfigured());
    }

    public function test_blank_secret_keeps_the_old_one_and_secret_change_updates_embedded_accounts(): void
    {
        $this->configurePlatform(webhookVerified: true);
        $embedded = WhatsAppAccount::factory()->create(['app_secret' => $this->platformSecret]);
        $embedded->forceFill(['onboarding_method' => WhatsAppAccount::ONBOARD_EMBEDDED])->save();
        $manual = WhatsAppAccount::factory()->create();
        $manualSecret = $manual->app_secret;
        $admin = $this->superAdmin();

        $this->actingAs($admin)->put('/settings/meta', $this->form(['app_secret' => '']))->assertSessionHas('success');
        Setting::flushCache();
        $this->assertSame($this->platformSecret, app(MetaPlatform::class)->appSecret());

        $this->actingAs($admin)->put('/settings/meta', $this->form())->assertSessionHas('success');
        Setting::flushCache();

        $this->assertSame('brandnewsecret0123456789abcdef00', $embedded->fresh()->app_secret);
        $this->assertSame($manualSecret, $manual->fresh()->app_secret);
        $this->assertNull(app(MetaPlatform::class)->webhookVerifiedAt()); // must be verified again
    }

    public function test_secret_is_masked_on_the_settings_page(): void
    {
        $this->configurePlatform();

        $this->actingAs($this->superAdmin())->get('/settings/meta')->assertOk()->assertDontSee($this->platformSecret);
    }

    public function test_app_credentials_can_be_tested(): void
    {
        $this->configurePlatform();
        Http::fake([$this->graph('111222333444*') => Http::response(['id' => '111222333444', 'name' => 'KN Softic Platform'])]);

        $this->actingAs($this->superAdmin())->post('/settings/meta/test')->assertSessionHas('success', fn ($m) => str_contains($m, 'KN Softic Platform'));
    }
}
