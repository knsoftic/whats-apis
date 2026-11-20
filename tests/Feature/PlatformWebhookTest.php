<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Setting;
use App\Models\WhatsAppAccount;
use App\Support\MetaPlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ConfiguresMetaPlatform;
use Tests\Concerns\SendsWebhooks;
use Tests\TestCase;

class PlatformWebhookTest extends TestCase
{
    use ConfiguresMetaPlatform, RefreshDatabase, SendsWebhooks;

    private function postPlatform(array $payload, ?string $secret = null): TestResponse
    {
        $raw = json_encode($payload);

        return $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, $secret ?? $this->platformSecret),
        ], $raw);
    }

    private function embeddedAccount(array $attributes = []): WhatsAppAccount
    {
        return WhatsAppAccount::factory()->create(array_merge([
            'app_secret' => $this->platformSecret,
        ], $attributes))->forceFill(['onboarding_method' => WhatsAppAccount::ONBOARD_EMBEDDED]);
    }

    public function test_platform_webhook_is_hidden_until_configured(): void
    {
        Setting::flushCache();

        $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=x&hub.challenge=1')->assertNotFound();
        $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [], '{}')->assertNotFound();
    }

    public function test_meta_can_verify_the_platform_webhook(): void
    {
        $this->configurePlatform();
        $account = $this->embeddedAccount();
        $account->save();

        $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=platform-verify-token&hub.challenge=987654')
            ->assertOk()->assertSeeText('987654');

        Setting::flushCache();
        $this->assertNotNull(app(MetaPlatform::class)->webhookVerifiedAt());
        $this->assertTrue($account->fresh()->isWebhookVerified());

        $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=1')->assertForbidden();
    }

    public function test_signed_platform_event_is_routed_to_the_embedded_signup_number(): void
    {
        $this->configurePlatform();
        $account = $this->embeddedAccount();
        $account->save();

        $this->postPlatform($this->textPayload($account, 'Hello via platform', 'wamid.PLAT1'))->assertOk()->assertSeeText('EVENT_RECEIVED');

        $message = Message::firstOrFail();
        $this->assertSame($account->id, $message->whatsapp_account_id);
        $this->assertSame('Hello via platform', $message->body);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $this->configurePlatform();
        $account = $this->embeddedAccount();
        $account->save();

        $this->postPlatform($this->textPayload($account), secret: 'someone-elses-secret')->assertStatus(401);
        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_unknown_number_is_acknowledged_without_storing(): void
    {
        $this->configurePlatform();
        $ghost = WhatsAppAccount::factory()->make(['phone_number_id' => '100000000999999', 'waba_id' => '200000000999999']);

        $this->postPlatform($this->textPayload($ghost))->assertOk()->assertSeeText('NO_ACCOUNT');
        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_number_belonging_to_a_different_meta_app_is_never_matched(): void
    {
        $this->configurePlatform();
        $manual = WhatsAppAccount::factory()->create(); // own app, different App Secret

        $this->postPlatform($this->textPayload($manual, 'spoof attempt'))->assertOk()->assertSeeText('NO_ACCOUNT');
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_template_status_without_phone_metadata_is_routed_by_waba(): void
    {
        $this->configurePlatform();
        $account = $this->embeddedAccount();
        $account->save();
        $template = MessageTemplate::create(['whatsapp_account_id' => $account->id, 'meta_template_id' => '42', 'name' => 'promo', 'language' => 'en', 'status' => 'PENDING', 'components' => []]);

        $payload = ['object' => 'whatsapp_business_account', 'entry' => [['id' => $account->waba_id, 'changes' => [[
            'field' => 'message_template_status_update',
            'value' => ['event' => 'APPROVED', 'message_template_id' => 42, 'message_template_name' => 'promo', 'message_template_language' => 'en', 'reason' => 'NONE'],
        ]]]]];

        $this->postPlatform($payload)->assertOk()->assertSeeText('EVENT_RECEIVED');
        $this->assertSame('APPROVED', $template->fresh()->status);
    }
}
