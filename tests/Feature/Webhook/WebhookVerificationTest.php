<?php

namespace Tests\Feature\Webhook;

use App\Models\WhatsAppAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function verifyUrl(WhatsAppAccount $account, array $query): string
    {
        return "/api/webhooks/whatsapp/{$account->uuid}?".http_build_query($query);
    }

    public function test_meta_verification_returns_the_challenge_and_marks_webhook_verified(): void
    {
        $account = WhatsAppAccount::factory()->create(['webhook_verify_token' => 'secret-verify-123']);

        $this->get($this->verifyUrl($account, [
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'secret-verify-123',
            'hub.challenge' => '1158201444',
        ]))->assertOk()->assertSeeText('1158201444')->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

        $account->refresh();
        $this->assertSame(WhatsAppAccount::WEBHOOK_VERIFIED, $account->webhook_status);
        $this->assertNotNull($account->webhook_verified_at);
    }

    public function test_wrong_verify_token_is_rejected(): void
    {
        $account = WhatsAppAccount::factory()->create(['webhook_verify_token' => 'secret-verify-123']);

        $this->get($this->verifyUrl($account, [
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'guess',
            'hub.challenge' => '123',
        ]))->assertForbidden();

        $this->assertSame(WhatsAppAccount::WEBHOOK_NOT_VERIFIED, $account->fresh()->webhook_status);
    }

    public function test_wrong_mode_or_missing_token_is_rejected(): void
    {
        $account = WhatsAppAccount::factory()->create(['webhook_verify_token' => 'secret-verify-123']);

        $this->get($this->verifyUrl($account, ['hub.mode' => 'unsubscribe', 'hub.verify_token' => 'secret-verify-123', 'hub.challenge' => '1']))->assertForbidden();
        $this->get($this->verifyUrl($account, ['hub.mode' => 'subscribe', 'hub.challenge' => '1']))->assertForbidden();
    }

    public function test_challenge_is_never_reflected_as_html(): void
    {
        $account = WhatsAppAccount::factory()->create(['webhook_verify_token' => 'secret-verify-123']);

        $this->get($this->verifyUrl($account, [
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'secret-verify-123',
            'hub.challenge' => '<script>alert(1)</script>',
        ]))->assertForbidden();
    }

    public function test_unknown_account_returns_404(): void
    {
        $this->get('/api/webhooks/whatsapp/9b2d9d8e-1111-4c4c-9a9a-000000000000?hub.mode=subscribe&hub.verify_token=x&hub.challenge=1')->assertNotFound();
        $this->get('/api/webhooks/whatsapp/1?hub.mode=subscribe')->assertNotFound(); // numeric ids are not accepted
    }

    public function test_verify_token_of_another_account_does_not_work(): void
    {
        $a = WhatsAppAccount::factory()->create(['webhook_verify_token' => 'token-of-a']);
        $b = WhatsAppAccount::factory()->create(['webhook_verify_token' => 'token-of-b']);

        $this->get($this->verifyUrl($b, ['hub.mode' => 'subscribe', 'hub.verify_token' => 'token-of-a', 'hub.challenge' => '5']))->assertForbidden();
        $this->assertFalse($b->fresh()->isWebhookVerified());
        $this->assertFalse($a->fresh()->isWebhookVerified());
    }
}
