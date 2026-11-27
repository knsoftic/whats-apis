<?php

namespace Tests\Feature\Webhook;

use App\Models\MessageTemplate;
use App\Models\WhatsAppAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SendsWebhooks;
use Tests\TestCase;

/**
 * One business portfolio, several WhatsApp Business Accounts (WABAs) on one Meta app
 * → one callback URL. A ban / quality / template event of one WABA must never show
 * up on the other numbers.
 */
class WabaIsolationTest extends TestCase
{
    use RefreshDatabase, SendsWebhooks;

    private WhatsAppAccount $a;

    private WhatsAppAccount $b;

    protected function setUp(): void
    {
        parent::setUp();
        // Same Meta app (same App Secret), different WABAs. A's callback URL is the one set in Meta.
        $this->a = WhatsAppAccount::factory()->connected()->create(['name' => 'Alpha', 'waba_id' => '111000000000001', 'phone_number_id' => '911000000000001', 'display_phone_number' => '+92 300 1111111', 'app_secret' => 'shared-secret-123']);
        $this->b = WhatsAppAccount::factory()->connected()->create(['name' => 'Beta', 'waba_id' => '222000000000002', 'phone_number_id' => '922000000000002', 'display_phone_number' => '+92 300 2222222', 'app_secret' => 'shared-secret-123']);
    }

    /** Event about $wabaId, delivered to A's callback URL. */
    private function event(string $wabaId, string $field, array $value): void
    {
        $this->postWebhook($this->a, [
            'object' => 'whatsapp_business_account',
            'entry' => [['id' => $wabaId, 'time' => now()->timestamp, 'changes' => [['field' => $field, 'value' => $value]]]],
        ])->assertOk();
    }

    public function test_a_banned_waba_only_marks_its_own_numbers(): void
    {
        // Meta disables WABA A (the one whose URL receives everything) …
        $this->event($this->a->waba_id, 'account_update', ['event' => 'DISABLED_UPDATE', 'ban_info' => ['waba_ban_state' => 'DISABLE', 'waba_ban_date' => '2026-11-27']]);

        $this->assertTrue($this->a->fresh()->metaRestriction()['banned']);
        $this->assertNull($this->b->fresh()->metaRestriction(), 'the other WABA must stay clean');

        $admin = $this->admin();
        $this->actingAs($admin)->get("/whatsapp-accounts/{$this->a->id}")->assertSee('Disabled by Meta')->assertSee('other WhatsApp Business Accounts keep working');
        $this->actingAs($admin)->get("/whatsapp-accounts/{$this->b->id}")->assertDontSee('Disabled by Meta');
        $this->actingAs($admin)->get('/whatsapp-accounts')->assertSee('Disabled by Meta');

        // … and later reinstates it.
        $this->event($this->a->waba_id, 'account_update', ['event' => 'DISABLED_UPDATE', 'ban_info' => ['waba_ban_state' => 'REINSTATE']]);
        $this->assertNull($this->a->fresh()->metaRestriction());
    }

    public function test_events_of_another_waba_arriving_at_this_url_go_to_that_waba(): void
    {
        $this->event($this->b->waba_id, 'account_update', ['event' => 'ACCOUNT_RESTRICTION', 'restriction_info' => [['restriction_type' => 'RESTRICTED_CUSTOMER_INITIATED_MESSAGING']]]);
        $this->assertSame('Restricted by Meta', $this->b->fresh()->metaRestriction()['label']);
        $this->assertNull($this->a->fresh()->metaRestriction());

        // Quality update names the number by display_phone_number.
        $this->event($this->b->waba_id, 'phone_number_quality_update', ['display_phone_number' => '923002222222', 'event' => 'FLAGGED', 'current_limit' => 'TIER_250']);
        $this->assertSame('FLAGGED', $this->b->fresh()->phone_meta['quality_event']);
        $this->assertArrayNotHasKey('quality_event', $this->a->fresh()->phone_meta ?? []);

        // Template status: only B's copy of the template changes.
        $tplA = MessageTemplate::create(['whatsapp_account_id' => $this->a->id, 'meta_template_id' => 'T-A', 'name' => 'promo', 'language' => 'en', 'category' => 'MARKETING', 'status' => 'APPROVED', 'components' => []]);
        $tplB = MessageTemplate::create(['whatsapp_account_id' => $this->b->id, 'meta_template_id' => 'T-B', 'name' => 'promo', 'language' => 'en', 'category' => 'MARKETING', 'status' => 'APPROVED', 'components' => []]);
        $this->event($this->b->waba_id, 'message_template_status_update', ['event' => 'PAUSED', 'message_template_name' => 'promo', 'message_template_language' => 'en', 'reason' => 'NONE']);
        $this->assertSame('PAUSED', $tplB->fresh()->status);
        $this->assertSame('APPROVED', $tplA->fresh()->status);
    }

    public function test_unknown_waba_or_number_is_ignored_not_pinned_on_the_receiver(): void
    {
        $this->event('999000000000009', 'account_update', ['event' => 'DISABLED_UPDATE', 'ban_info' => ['waba_ban_state' => 'DISABLE']]);
        $this->event($this->a->waba_id, 'phone_number_quality_update', ['display_phone_number' => '15550009999', 'event' => 'FLAGGED']);

        $this->assertNull($this->a->fresh()->metaRestriction());
        $this->assertNull($this->b->fresh()->metaRestriction());
        $this->assertArrayNotHasKey('quality_event', $this->a->fresh()->phone_meta ?? []);
    }

    public function test_a_waba_of_another_meta_app_is_never_touched(): void
    {
        $other = WhatsAppAccount::factory()->connected()->create(['waba_id' => $this->b->waba_id, 'phone_number_id' => '933000000000003', 'app_secret' => 'another-apps-secret']);

        $this->event($this->b->waba_id, 'account_update', ['event' => 'DISABLED_UPDATE', 'ban_info' => ['waba_ban_state' => 'DISABLE']]);

        $this->assertTrue($this->b->fresh()->metaRestriction()['banned']);
        $this->assertNull($other->fresh()->metaRestriction());
    }

    public function test_switching_a_number_off_here_is_labelled_differently_from_a_meta_ban(): void
    {
        $this->b->forceFill(['is_active' => false])->save();

        $this->actingAs($this->admin())->get('/whatsapp-accounts')->assertSee('Disabled here')->assertDontSee('Disabled by Meta');
    }
}
