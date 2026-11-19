<?php

namespace Tests\Feature\Webhook;

use App\Jobs\ProcessWebhookEvent;
use App\Models\WhatsAppAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\SendsWebhooks;
use Tests\TestCase;

class WebhookSignatureTest extends TestCase
{
    use RefreshDatabase, SendsWebhooks;

    public function test_valid_signature_is_accepted_and_queued(): void
    {
        Queue::fake();
        $account = WhatsAppAccount::factory()->create();

        $this->postWebhook($account, $this->textPayload($account))->assertOk()->assertSeeText('EVENT_RECEIVED');

        $this->assertDatabaseCount('webhook_events', 1);
        Queue::assertPushed(ProcessWebhookEvent::class, 1);
        $this->assertNotNull($account->fresh()->last_webhook_at);
    }

    public function test_missing_signature_is_rejected(): void
    {
        Queue::fake();
        $account = WhatsAppAccount::factory()->create();

        $this->postWebhook($account, $this->textPayload($account), sign: false)->assertStatus(401);

        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertDatabaseCount('messages', 0);
        Queue::assertNothingPushed();
    }

    public function test_signature_made_with_wrong_secret_is_rejected(): void
    {
        Queue::fake();
        $account = WhatsAppAccount::factory()->create();

        $this->postWebhook($account, $this->textPayload($account), secret: 'not-the-app-secret')->assertStatus(401);

        $this->assertDatabaseCount('webhook_events', 0);
        Queue::assertNothingPushed();
    }

    public function test_signature_from_another_accounts_app_is_rejected(): void
    {
        Queue::fake();
        $victim = WhatsAppAccount::factory()->create();
        $attacker = WhatsAppAccount::factory()->create();

        $this->postWebhook($victim, $this->textPayload($victim), secret: $attacker->app_secret)->assertStatus(401);
        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_tampered_body_is_rejected(): void
    {
        Queue::fake();
        $account = WhatsAppAccount::factory()->create();
        $raw = json_encode($this->textPayload($account, 'original'));
        $signature = 'sha256='.hash_hmac('sha256', $raw, $account->app_secret);
        $tampered = str_replace('original', 'tampered', $raw);

        $this->call('POST', "/api/webhooks/whatsapp/{$account->uuid}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $tampered)->assertStatus(401);
    }

    public function test_non_whatsapp_object_is_acknowledged_but_ignored(): void
    {
        Queue::fake();
        $account = WhatsAppAccount::factory()->create();

        $this->postWebhook($account, ['object' => 'page', 'entry' => []])->assertOk()->assertSeeText('IGNORED');
        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_valid_signed_delivery_marks_webhook_as_verified(): void
    {
        Queue::fake();
        $account = WhatsAppAccount::factory()->create();
        $this->assertFalse($account->isWebhookVerified());

        $this->postWebhook($account, $this->textPayload($account))->assertOk();

        $this->assertTrue($account->fresh()->isWebhookVerified());
    }
}
