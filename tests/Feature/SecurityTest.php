<?php

namespace Tests\Feature;

use App\Logging\RedactSecrets;
use App\Models\WebhookEvent;
use App\Models\WhatsAppAccount;
use App\Services\ActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesMeta;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use FakesMeta, RefreshDatabase;

    public function test_log_redaction_removes_tokens_and_secrets(): void
    {
        $line = RedactSecrets::scrub('GET https://graph.facebook.com/v26.0/1?appsecret_proof=abcdef0123456789abcdef Bearer EAAGabcdefghijklmnopqrstuvwxyz0123456789 sig sha256=0123456789abcdef0123456789abcdef01234567');

        $this->assertStringNotContainsString('EAAGabcdefghijklmnopqrstuvwxyz', $line);
        $this->assertStringNotContainsString('abcdef0123456789abcdef', $line);
        $this->assertStringNotContainsString('0123456789abcdef0123456789abcdef01234567', $line);

        $context = RedactSecrets::scrubArray(['access_token' => 'EAAGsecret', 'nested' => ['app_secret' => 'xyz', 'ok' => 'visible']]);
        $this->assertSame('[redacted]', $context['access_token']);
        $this->assertSame('[redacted]', $context['nested']['app_secret']);
        $this->assertSame('visible', $context['nested']['ok']);
    }

    public function test_activity_log_never_stores_secret_values(): void
    {
        $log = app(ActivityLogger::class)->log('test.secret', 'test', properties: [
            'access_token' => 'EAAG-should-not-be-stored',
            'nested' => ['password' => 'p4ss', 'name' => 'ok'],
        ]);

        $this->assertSame('[redacted]', $log->properties['access_token']);
        $this->assertSame('[redacted]', $log->properties['nested']['password']);
        $this->assertSame('ok', $log->properties['nested']['name']);
    }

    public function test_webhook_endpoint_is_rate_limited(): void
    {
        config(['whatsapp.webhook.rate_limit' => 3]);
        $account = WhatsAppAccount::factory()->create();
        $url = "/api/webhooks/whatsapp/{$account->uuid}?hub.mode=subscribe&hub.verify_token=x&hub.challenge=1";

        foreach (range(1, 3) as $i) {
            $this->get($url)->assertForbidden();
        }

        $this->get($url)->assertStatus(429);
    }

    public function test_login_is_rate_limited(): void
    {
        $user = $this->agent();

        foreach (range(1, 5) as $i) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_csrf_protection_is_active_for_web_forms(): void
    {
        $this->assertContains(
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            app(\Illuminate\Contracts\Http\Kernel::class)->getMiddlewareGroups()['web'],
        );
    }

    public function test_no_secrets_are_exposed_to_javascript_on_the_chat_page(): void
    {
        $account = WhatsAppAccount::factory()->connected()->create();

        $html = $this->actingAs($this->admin())->get('/chat')->assertOk()->getContent();

        $this->assertStringNotContainsString($account->access_token, $html);
        $this->assertStringNotContainsString($account->app_secret, $html);
        $this->assertStringNotContainsString($account->webhook_verify_token, $html);
        $this->assertStringNotContainsString((string) config('broadcasting.connections.reverb.secret'), $html);
    }

    public function test_check_accounts_command_revalidates_active_accounts(): void
    {
        $account = WhatsAppAccount::factory()->create();
        $this->fakeMetaValid($account->waba_id, $account->phone_number_id);

        Artisan::call('whatsapp:check-accounts');

        $this->assertSame(WhatsAppAccount::API_CONNECTED, $account->fresh()->api_status);
    }

    public function test_old_processed_webhook_payloads_are_pruned(): void
    {
        $account = WhatsAppAccount::factory()->create();
        $old = WebhookEvent::create(['whatsapp_account_id' => $account->id, 'payload_hash' => str_repeat('a', 64), 'payload' => '{}', 'status' => 'processed']);
        $old->forceFill(['created_at' => now()->subDays(40)])->save();
        $failed = WebhookEvent::create(['whatsapp_account_id' => $account->id, 'payload_hash' => str_repeat('b', 64), 'payload' => '{}', 'status' => 'failed']);
        $failed->forceFill(['created_at' => now()->subDays(40)])->save();

        Artisan::call('model:prune', ['--model' => [WebhookEvent::class]]);

        $this->assertModelMissing($old);
        $this->assertModelExists($failed);
        Http::assertNothingSent();
    }
}
