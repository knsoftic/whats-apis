<?php

namespace Tests\Feature;

use App\Models\BotFlow;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Plan;
use App\Models\User;
use App\Models\WhatsAppAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesMeta;
use Tests\Concerns\SendsWebhooks;
use Tests\TestCase;

class BotFlowTest extends TestCase
{
    use FakesMeta, RefreshDatabase, SendsWebhooks;

    private WhatsAppAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = WhatsAppAccount::factory()->connected()->create();
        Http::fake([$this->graph($this->account->phone_number_id.'/messages*') => $this->metaSendOkEachTime()]);
    }

    private function flow(array $attributes): BotFlow
    {
        return BotFlow::create(array_merge([
            'whatsapp_account_id' => $this->account->id, 'name' => 'Flow', 'trigger_type' => 'keyword',
            'match_type' => 'contains', 'keywords' => null, 'priority' => 0, 'cooldown_minutes' => 0,
            'is_active' => true, 'steps' => [['type' => 'text', 'text' => 'Hi']],
        ], $attributes));
    }

    private function sentBodies(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $r) => str_contains($r->url(), '/messages') && ($r['type'] ?? null) !== null)
            ->map(fn (Request $r) => $r->data())->values()->all();
    }

    public function test_keyword_flow_replies_automatically(): void
    {
        $this->flow(['name' => 'Prices', 'keywords' => 'price, rates', 'steps' => [
            ['type' => 'text', 'text' => 'Our plans start at ₹999.'],
            ['type' => 'image', 'url' => 'https://example.com/prices.png', 'caption' => 'Price list'],
        ]]);

        $this->postWebhook($this->account, $this->textPayload($this->account, 'What is the PRICE?', 'wamid.KW1'))->assertOk();

        $sent = $this->sentBodies();
        $this->assertCount(2, $sent);
        $this->assertSame('Our plans start at ₹999.', $sent[0]['text']['body']);
        $this->assertSame('image', $sent[1]['type']);
        $this->assertSame('https://example.com/prices.png', $sent[1]['image']['link']);

        $bot = Message::where('direction', 'outbound')->first();
        $this->assertNull($bot->sent_by_user_id);
        $this->assertSame('sent', $bot->status);
        $this->assertSame(1, BotFlow::first()->runs_count);
    }

    public function test_welcome_menu_with_buttons_and_button_reply_opens_target_flow(): void
    {
        $sales = $this->flow(['name' => 'Sales', 'trigger_type' => 'menu', 'steps' => [['type' => 'text', 'text' => 'Sales team here!']]]);
        $support = $this->flow(['name' => 'Support', 'trigger_type' => 'menu', 'steps' => [['type' => 'handoff', 'text' => 'Connecting you to support.']]]);
        $this->flow(['name' => 'Welcome', 'trigger_type' => 'welcome', 'steps' => [[
            'type' => 'menu', 'text' => 'Welcome! Choose:', 'footer' => 'KN Softic',
            'options' => [['title' => 'Sales', 'flow_id' => $sales->id], ['title' => 'Support', 'flow_id' => $support->id]],
        ]]]);

        // First message → welcome menu as reply buttons
        $this->postWebhook($this->account, $this->textPayload($this->account, 'hello', 'wamid.W1'))->assertOk();
        $menu = $this->sentBodies()[0];
        $this->assertSame('interactive', $menu['type']);
        $this->assertSame('button', $menu['interactive']['type']);
        $this->assertSame('bf:'.$sales->id, $menu['interactive']['action']['buttons'][0]['reply']['id']);
        $this->assertSame('KN Softic', $menu['interactive']['footer']['text']);

        // Second message (not first any more) + no keyword/fallback → nothing
        $this->postWebhook($this->account, $this->textPayload($this->account, 'just chatting', 'wamid.W2'))->assertOk();
        $this->assertCount(1, $this->sentBodies());

        // Customer taps "Support" → handoff flow runs and pauses the bot
        $this->postWebhook($this->account, $this->incomingPayload($this->account, [
            'id' => 'wamid.W3', 'type' => 'interactive',
            'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'bf:'.$support->id, 'title' => 'Support']],
        ]))->assertOk();

        $this->assertSame('Connecting you to support.', $this->sentBodies()[1]['text']['body']);
        $this->assertTrue(Conversation::firstOrFail()->isBotPaused());

        // Bot is paused → keyword flows no longer answer in this chat
        $this->flow(['name' => 'Any', 'keywords' => 'price']);
        $this->postWebhook($this->account, $this->textPayload($this->account, 'price?', 'wamid.W4'))->assertOk();
        $this->assertCount(2, $this->sentBodies());
    }

    public function test_more_than_three_options_become_a_list(): void
    {
        $targets = collect(range(1, 4))->map(fn ($i) => $this->flow(['name' => "T{$i}", 'trigger_type' => 'menu']));
        $this->flow(['name' => 'Menu', 'keywords' => 'menu', 'steps' => [[
            'type' => 'menu', 'text' => 'Pick one', 'button_label' => 'Options',
            'options' => $targets->map(fn ($t) => ['title' => $t->name, 'description' => 'Desc '.$t->name, 'flow_id' => $t->id])->all(),
        ]]]);

        $this->postWebhook($this->account, $this->textPayload($this->account, 'menu', 'wamid.L1'));

        $list = $this->sentBodies()[0]['interactive'];
        $this->assertSame('list', $list['type']);
        $this->assertSame('Options', $list['action']['button']);
        $this->assertCount(4, $list['action']['sections'][0]['rows']);
    }

    public function test_fallback_respects_cooldown(): void
    {
        $this->flow(['name' => 'Fallback', 'trigger_type' => 'fallback', 'cooldown_minutes' => 60, 'steps' => [['type' => 'text', 'text' => 'We will reply soon.']]]);

        $this->postWebhook($this->account, $this->textPayload($this->account, 'a', 'wamid.F1'));
        $this->postWebhook($this->account, $this->textPayload($this->account, 'b', 'wamid.F2'));
        $this->assertCount(1, $this->sentBodies());

        $this->travel(61)->minutes();
        $this->postWebhook($this->account, $this->textPayload($this->account, 'c', 'wamid.F3'));
        $this->assertCount(2, $this->sentBodies());
    }

    public function test_agent_reply_pauses_the_bot(): void
    {
        $this->flow(['keywords' => 'hi']);
        $this->postWebhook($this->account, $this->textPayload($this->account, 'hi', 'wamid.P1'));
        $this->assertCount(1, $this->sentBodies());

        $conversation = Conversation::firstOrFail();
        $this->actingAs($this->admin())->postJson("/chat/conversations/{$conversation->id}/messages", ['body' => 'Human here'])->assertCreated();
        $this->assertTrue($conversation->fresh()->isBotPaused());

        $this->postWebhook($this->account, $this->textPayload($this->account, 'hi again', 'wamid.P2'));
        $this->assertCount(2, $this->sentBodies()); // only the agent's message, no new bot reply
    }

    public function test_plan_without_bots_and_daily_limit_stop_the_bot(): void
    {
        $plan = Plan::create(['name' => 'NoBots', 'slug' => 'nobots', 'price' => 0, 'currency' => 'INR', 'billing_period' => 'free', 'allow_bot_flows' => false, 'is_active' => true]);
        $owner = User::factory()->withRole('Customer')->create(['plan_id' => $plan->id]);
        $this->account->forceFill(['owner_id' => $owner->id])->save();
        $this->flow(['keywords' => 'hi']);

        $this->postWebhook($this->account, $this->textPayload($this->account, 'hi', 'wamid.N1'));
        $this->assertCount(0, $this->sentBodies());

        $plan->update(['allow_bot_flows' => true, 'daily_message_limit' => 1]);
        $this->flow(['keywords' => 'two', 'steps' => [['type' => 'text', 'text' => 'one'], ['type' => 'text', 'text' => 'two']]]);
        $this->postWebhook($this->account, $this->textPayload($this->account, 'two', 'wamid.N2'));
        $this->assertCount(1, $this->sentBodies()); // second step blocked by the daily limit
    }

    public function test_bot_flow_builder_validates_whatsapp_limits(): void
    {
        $admin = $this->admin();
        $target = $this->flow(['name' => 'Target', 'trigger_type' => 'menu']);

        $this->actingAs($admin)->post('/bot-flows', [
            'whatsapp_account_id' => $this->account->id, 'name' => 'Bad', 'trigger_type' => 'keyword', 'match_type' => 'contains',
            'keywords' => 'x', 'is_active' => '1',
            'steps' => json_encode([['type' => 'menu', 'text' => 'Pick', 'options' => [['title' => str_repeat('a', 21), 'flow_id' => $target->id]]]]),
        ])->assertSessionHasErrors('steps.0');

        $this->actingAs($admin)->post('/bot-flows', [
            'whatsapp_account_id' => $this->account->id, 'name' => 'Good', 'trigger_type' => 'keyword', 'match_type' => 'exact',
            'keywords' => 'menu', 'is_active' => '1', 'priority' => '5',
            'steps' => json_encode([['type' => 'menu', 'text' => 'Pick', 'options' => [['title' => 'Target', 'flow_id' => $target->id]]]]),
        ])->assertRedirect();

        $good = BotFlow::where('name', 'Good')->firstOrFail();
        $this->assertSame($target->id, $good->steps[0]['options'][0]['flow_id']);
        $this->actingAs($admin)->get("/bot-flows/{$good->id}/edit")->assertOk()->assertSee('Good');
        $this->actingAs($admin)->get('/bot-flows?account_id='.$this->account->id)->assertOk()->assertSee('Good');
    }

    public function test_customer_cannot_touch_another_numbers_flows(): void
    {
        $flow = $this->flow(['name' => 'Private']);
        $plan = Plan::create(['name' => 'P', 'slug' => 'p', 'price' => 0, 'currency' => 'INR', 'billing_period' => 'free', 'allow_bot_flows' => true, 'is_active' => true]);
        $other = User::factory()->withRole('Customer')->create(['plan_id' => $plan->id]);

        $this->actingAs($other)->get("/bot-flows/{$flow->id}/edit")->assertNotFound();
        $this->actingAs($other)->delete("/bot-flows/{$flow->id}")->assertNotFound();
        $this->assertModelExists($flow);
    }
}
