<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Plan;
use App\Models\User;
use App\Models\WhatsAppAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesMeta;
use Tests\Concerns\SendsWebhooks;
use Tests\TestCase;

/** Automatic follow-up before the 24-hour WhatsApp window closes. */
class FollowUpTest extends TestCase
{
    use FakesMeta, RefreshDatabase, SendsWebhooks;

    private WhatsAppAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = WhatsAppAccount::factory()->connected()->create([
            'name' => 'Shop', 'verified_name' => 'Shop PK',
            'followup_enabled' => true, 'followup_after_minutes' => 1380, 'followup_skip_closed' => true,
            'followup_message' => 'Hi {{contact_name}}, anything else from {{business_name}}? Reply to continue.',
        ]);
        Http::fake([$this->graph($this->account->phone_number_id.'/messages*') => $this->metaSendOkEachTime()]);
    }

    private function chat(string $lastInboundAgo, array $contact = [], array $conversation = []): Conversation
    {
        $c = Contact::factory()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Ali'] + $contact);

        return Conversation::create(array_merge([
            'whatsapp_account_id' => $this->account->id, 'contact_id' => $c->id, 'status' => Conversation::STATUS_OPEN,
            'last_inbound_at' => now()->sub(\Carbon\CarbonInterval::fromString($lastInboundAgo)),
        ], $conversation));
    }

    private function run_(): int
    {
        Artisan::call('whatsapp:send-followups');

        return Message::where('content->followup', true)->count();
    }

    private function sentTexts(): array
    {
        return collect(Http::recorded())->map(fn ($p) => $p[0])
            ->filter(fn (Request $r) => str_contains($r->url(), '/messages') && ($r['type'] ?? null) === 'text')
            ->map(fn (Request $r) => $r['text']['body'])->values()->all();
    }

    public function test_follow_up_is_sent_once_after_23_hours(): void
    {
        $chat = $this->chat('23 hours 5 minutes');

        $this->assertSame(1, $this->run_());
        $this->assertSame(['Hi Ali, anything else from Shop PK? Reply to continue.'], $this->sentTexts());

        $message = Message::where('content->followup', true)->firstOrFail();
        $this->assertSame(Message::STATUS_SENT, $message->status);
        $this->assertSame($chat->id, $message->conversation_id);
        $this->assertNull($message->sent_by_user_id);
        $chat->refresh();
        $this->assertTrue($chat->followup_for_inbound_at->equalTo($chat->last_inbound_at));
        $this->assertNotNull($chat->followup_sent_at);

        // Runs every 5 minutes — never twice for the same customer message.
        $this->travel(5)->minutes();
        $this->assertSame(1, $this->run_());

        // Customer writes again → a new window → a new follow-up 23 hours later.
        $this->postWebhook($this->account, $this->textPayload($this->account, 'ok thanks', 'wamid.NEW', $chat->contact->wa_id))->assertOk();
        $this->assertSame(1, $this->run_());
        $this->travel(23)->hours();
        $this->travel(1)->minutes();
        $this->assertSame(2, $this->run_());
    }

    public function test_not_sent_too_early_too_late_or_to_the_wrong_people(): void
    {
        $this->chat('22 hours');                                                      // too early
        $this->chat('23 hours 55 minutes');                                           // window closes in < 10 min
        $this->chat('25 hours');                                                      // window already closed
        $this->chat('23 hours 10 minutes', [], ['status' => Conversation::STATUS_CLOSED]); // closed chat
        $this->chat('23 hours 10 minutes', ['opt_in_status' => Contact::OPT_OUT]);    // opted out
        $this->chat('23 hours 10 minutes', ['blocked_at' => now()]);                  // blocked

        $this->assertSame(0, $this->run_());

        // Closed chats are included when that option is off.
        $this->account->forceFill(['followup_skip_closed' => false])->save();
        $this->assertSame(1, $this->run_());
    }

    public function test_disabled_number_and_suspended_owner_get_nothing(): void
    {
        $this->chat('23 hours 10 minutes');
        $this->account->forceFill(['followup_enabled' => false])->save();
        $this->assertSame(0, $this->run_());

        // Customer-owned number whose owner is suspended → skipped (and not retried every 5 min).
        $plan = Plan::create(['name' => 'P', 'slug' => 'p', 'price' => 0, 'currency' => 'PKR', 'billing_period' => 'free', 'is_active' => true]);
        $owner = User::factory()->withRole('Customer')->create(['plan_id' => $plan->id, 'status' => User::STATUS_SUSPENDED]);
        $this->account->forceFill(['followup_enabled' => true, 'owner_id' => $owner->id])->save();

        $this->assertSame(0, $this->run_());
        $this->assertNotNull(Conversation::firstOrFail()->followup_for_inbound_at);
        Http::assertNothingSent();
    }

    public function test_follow_up_settings_page(): void
    {
        $admin = $this->admin();
        $this->chat('23 hours 10 minutes');

        $this->actingAs($admin)->get("/whatsapp-accounts/{$this->account->id}/manage/followup")->assertOk()
            ->assertSee('24-hour window')->assertSee('23 hours (recommended)')->assertSee('Due right now');

        $this->actingAs($admin)->post("/whatsapp-accounts/{$this->account->id}/manage/followup", ['enabled' => '1', 'after_minutes' => 1380, 'message' => ''])
            ->assertSessionHasErrors('message');
        $this->actingAs($admin)->post("/whatsapp-accounts/{$this->account->id}/manage/followup", ['enabled' => '1', 'after_minutes' => 1440, 'message' => 'Hi'])
            ->assertSessionHasErrors('after_minutes');

        $this->actingAs($admin)->post("/whatsapp-accounts/{$this->account->id}/manage/followup", [
            'enabled' => '1', 'after_minutes' => 1320, 'message' => 'Still there, {{contact_name}}?', 'skip_closed' => '0',
        ])->assertRedirect()->assertSessionHas('success');

        $this->account->refresh();
        $this->assertTrue($this->account->followup_enabled);
        $this->assertSame(1320, $this->account->followup_after_minutes);
        $this->assertFalse($this->account->followup_skip_closed);
        $this->assertSame('Still there, {{contact_name}}?', $this->account->followup_message);

        $this->actingAs($this->agent())->get("/whatsapp-accounts/{$this->account->id}/manage/followup")->assertForbidden();
    }

    public function test_scheduler_runs_it_every_five_minutes(): void
    {
        Artisan::call('schedule:list');
        $this->assertMatchesRegularExpression('#\*/5 \* \* \* \*\s+php artisan whatsapp:send-followups#', Artisan::output());
    }
}
