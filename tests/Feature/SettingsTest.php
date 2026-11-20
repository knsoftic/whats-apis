<?php

namespace Tests\Feature;

use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_super_admin_can_open_settings(): void
    {
        $this->actingAs($this->admin())->get('/settings/general')->assertForbidden();
        $this->actingAs($this->agent())->get('/settings/payments')->assertForbidden();

        $super = $this->superAdmin();
        foreach (['general', 'appearance', 'payments', 'email', 'legal'] as $section) {
            $this->actingAs($super)->get("/settings/{$section}")->assertOk()->assertSee('Settings');
        }
        $this->actingAs($super)->get('/settings')->assertRedirect('/settings/general');
        $this->actingAs($super)->get('/settings/meta')->assertOk();
    }

    public function test_site_name_and_logo_change_the_whole_app(): void
    {
        $super = $this->superAdmin();

        $this->actingAs($super)->put('/settings/general', [
            'site_name' => 'Acme Chat', 'site_tagline' => 'Business inbox', 'registration_enabled' => '0',
            'logo' => UploadedFile::fake()->image('logo.png', 64, 64),
        ])->assertRedirect('/settings/general');

        $this->actingAs($super)->get('/dashboard')->assertSee('Acme Chat')->assertSee('Business inbox')->assertSee('uploads/branding/logo-', false);
        $this->get('/privacy-policy')->assertSee('Acme Chat');
        $this->post('/logout');
        $this->get('/register')->assertNotFound();

        @array_map('unlink', glob(public_path('uploads/branding/logo-*')));
    }

    public function test_brand_colour_and_default_theme_are_applied(): void
    {
        $super = $this->superAdmin();

        $this->actingAs($super)->put('/settings/appearance', ['brand_color' => 'violet', 'default_theme' => 'dark', 'allow_toggle' => '0'])->assertRedirect();

        $html = $this->actingAs($super)->get('/dashboard')->getContent();
        $this->assertStringContainsString('--brand-600: 124 58 237;', $html);   // violet-600
        $this->assertStringContainsString("var def = 'dark'", $html);
        $this->assertStringNotContainsString('aria-label="Toggle dark mode"', $html);
    }

    public function test_payment_secrets_are_encrypted_and_masked(): void
    {
        $super = $this->superAdmin();

        $this->actingAs($super)->put('/settings/payments', [
            'currency' => 'PKR', 'self_purchase' => '1', 'pkpay_enabled' => '1', 'pkpay_base_url' => 'https://pkpay.site/api/v1',
            'pkpay_api_key' => 'pk_live_abcdef123456', 'pkpay_api_secret' => 'sk_live_supersecret987654',
        ])->assertSessionHas('success');

        $raw = DB::table('settings')->where('key', 'billing.pkpay_api_secret')->value('value');
        $this->assertStringNotContainsString('supersecret', $raw);
        $this->assertSame('sk_live_supersecret987654', app(PlatformSettings::class)->get('billing.pkpay_api_secret'));

        $this->actingAs($super)->get('/settings/payments')->assertDontSee('sk_live_supersecret987654')->assertSee('sk_l••••••••••••7654', false);

        // Blank secret keeps the saved one; wrong key format is rejected.
        $this->actingAs($super)->put('/settings/payments', ['currency' => 'PKR', 'pkpay_base_url' => 'https://pkpay.site/api/v1', 'pkpay_api_secret' => ''])->assertSessionHas('success');
        $this->assertSame('sk_live_supersecret987654', app(PlatformSettings::class)->get('billing.pkpay_api_secret'));
        $this->actingAs($super)->put('/settings/payments', ['currency' => 'PKR', 'pkpay_base_url' => 'https://pkpay.site/api/v1', 'pkpay_api_key' => 'nope'])->assertSessionHasErrors('pkpay_api_key');
    }

    public function test_smtp_settings_are_applied_at_runtime(): void
    {
        $super = $this->superAdmin();

        $this->actingAs($super)->put('/settings/email', [
            'mail_enabled' => '1', 'mail_host' => 'smtp.example.com', 'mail_port' => '587', 'mail_username' => 'u',
            'mail_password' => 'p4ss', 'mail_encryption' => 'tls', 'mail_from_address' => 'no-reply@example.com',
        ])->assertSessionHas('success');

        \App\Models\Setting::flushCache();
        app(PlatformSettings::class)->applyRuntimeConfig();
        $this->assertSame('smtp.example.com', config('mail.mailers.smtp.host'));
        $this->assertSame('no-reply@example.com', config('mail.from.address'));
    }

    public function test_legal_details_override_env(): void
    {
        $super = $this->superAdmin();
        $this->actingAs($super)->put('/settings/legal', ['legal_company' => 'KN Softic Pvt Ltd', 'legal_email' => 'legal@example.com', 'legal_jurisdiction' => 'Pakistan'])->assertSessionHas('success');

        $this->post('/logout');
        \App\Models\Setting::flushCache();
        app(PlatformSettings::class)->applyRuntimeConfig();

        $this->get('/terms-conditions')->assertSee('KN Softic Pvt Ltd')->assertSee('legal@example.com')->assertSee('laws of Pakistan');
    }
}
