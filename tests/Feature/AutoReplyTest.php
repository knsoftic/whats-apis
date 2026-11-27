<?php

namespace Tests\Feature;

use App\Jobs\DownloadMessageMedia;
use App\Models\AutoReply;
use App\Models\BotFlow;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Plan;
use App\Models\User;
use App\Models\WhatsAppAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesMeta;
use Tests\Concerns\SendsWebhooks;
use Tests\TestCase;

/** Auto Replies (always reply / auto reply / quick action buttons) — separate from Bot Flows. */
class AutoReplyTest extends TestCase
{
    use FakesMeta, RefreshDatabase, SendsWebhooks;

    private WhatsAppAccount $account;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([DownloadMessageMedia::class]);
        $this->account = WhatsAppAccount::factory()->connected()->create(['name' => 'Shop', 'verified_name' => 'Shop PK']);
        Http::fake([$this->graph($this->account->phone_number_id.'/messages*') => $this->metaSendOkEachTime()]);
    }

    private function rule(array $attributes = []): AutoReply
    {
        return AutoReply::create(array_merge([
            'whatsapp_account_id' => $this->account->id, 'name' => 'Rule', 'trigger' => 'always', 'match_type' => 'contains',
            'hours_mode' => 'always', 'reply_text' => 'Auto: hi {{contact_name}}', 'is_active' => true,
        ], $attributes));
    }

    /** Customer message (text by default) from one fixed number. */
    private function say(string $text = 'hello', string $type = 'text', string $from = '923001234567'): void
    {
        $message = match ($type) {
            'voice' => ['type' => 'audio', 'audio' => ['id' => 'M'.$this->n, 'mime_type' => 'audio/ogg; codecs=opus', 'voice' => true]],
            'image' => ['type' => 'image', 'image' => ['id' => 'M'.$this->n, 'mime_type' => 'image/jpeg']],
            'tap' => ['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $text, 'title' => 'Tap']]],
            default => ['type' => 'text', 'text' => ['body' => $text]],
        };
        $this->postWebhook($this->account, $this->incomingPayload($this->account, ['id' => 'wamid.AR'.(++$this->n), 'timestamp' => (string) now()->timestamp] + $message, $from, 'Ali'))->assertOk();
    }

    /** @return list<array<string, mixed>> bodies sent to Meta */
    private function sent(): array
    {
        return collect(Http::recorded())->map(fn ($p) => $p[0])
            ->filter(fn (Request $r) => str_contains($r->url(), '/messages') && isset($r['type']))
            ->map(fn (Request $r) => $r->data())->values()->all();
    }

    private function texts(): array
    {
        return collect($this->sent())->map(fn ($b) => $b['text']['body'] ?? $b['interactive']['body']['text'] ?? null)->values()->all();
    }

    public function test_first_message_rule_reads_the_chat_history(): void
    {
        $this->rule(['trigger' => 'first_message', 'reply_text' => 'Welcome {{contact_name}} to {{business_name}}!']);

        $this->say('hi');
        $this->say('are you there?');
        $this->say('hello', 'text', '923009999999'); // another new customer

        $this->assertSame(['Welcome Ali to Shop PK!', 'Welcome Ali to Shop PK!'], $this->texts());
        $msg = Message::where('content->auto_reply', '>', 0)->firstOrFail();
        $this->assertSame('⚡ Auto reply · Rule', (new \App\Http\Resources\MessageResource($msg))->resolve()['sent_by']);
    }

    public function test_returning_customer_only_after_the_quiet_hours(): void
    {
        $this->rule(['trigger' => 'returning', 'gap_hours' => 24, 'reply_text' => 'Welcome back!']);

        $this->say('first');                 // new customer → not "returning"
        $this->travel(2)->hours();
        $this->say('second');                // wrote 2 h ago → quiet time not reached
        $this->travel(25)->hours();
        $this->say('back again');            // 25 h later → welcome back

        $this->assertSame(['Welcome back!'], $this->texts());
    }

    public function test_keyword_and_message_type_rules_with_priority(): void
    {
        $this->rule(['trigger' => 'keyword', 'keywords' => 'price, rate', 'reply_text' => 'Prices: …', 'priority' => 10]);
        $this->rule(['trigger' => 'always', 'message_types' => ['voice'], 'reply_text' => 'Got your voice note 🎤']);

        $this->say('what is the price?');
        $this->say('', 'voice');
        $this->say('just text');           // neither rule
        $this->say('', 'image');

        $this->assertSame(['Prices: …', 'Got your voice note 🎤'], $this->texts());
    }

    public function test_stays_quiet_while_an_agent_chats_unless_told_otherwise(): void
    {
        $quiet = $this->rule(['reply_text' => 'Quiet rule']);
        $this->say('hi');                                        // answers (no agent yet)

        $conversation = Conversation::firstOrFail();
        Message::create([
            'whatsapp_account_id' => $this->account->id, 'conversation_id' => $conversation->id, 'contact_id' => $conversation->contact_id,
            'direction' => Message::OUT, 'type' => 'text', 'body' => 'Agent here', 'status' => Message::STATUS_SENT,
            'sent_by_user_id' => $this->admin()->id, 'wa_timestamp' => now(), 'sender' => 'biz', 'recipient' => '923001234567',
        ]);
        // Bot Flows' pause does not matter for auto replies.
        $conversation->forceFill(['bot_paused_until' => now()->addHour()])->save();

        $this->say('thanks');                                    // agent active → quiet
        $quiet->update(['while_chatting' => true]);
        $this->say('one more thing');                            // "also while chatting" → answers

        $this->travel(31)->minutes();
        $quiet->update(['while_chatting' => false]);
        $this->say('later');                                     // agent quiet for 30+ min → answers again

        $this->assertSame(['Quiet rule', 'Quiet rule', 'Quiet rule'], $this->texts());
    }

    public function test_quick_action_buttons_answer_when_tapped_and_stay_out_of_bot_flows(): void
    {
        $rule = $this->rule(['trigger' => 'first_message', 'reply_text' => 'How can we help?', 'buttons' => [
            ['id' => 'b1', 'title' => 'Prices', 'reply' => 'Our prices: …'],
            ['id' => 'b2', 'title' => 'Talk to us', 'reply' => 'An agent will reply soon.'],
        ]]);
        // An "any message" Bot Flow must not answer the auto reply button taps.
        BotFlow::create(['whatsapp_account_id' => $this->account->id, 'name' => 'Any', 'trigger_type' => 'any', 'match_type' => 'contains', 'is_active' => true,
            'steps' => [], 'graph' => ['nodes' => [['id' => 's', 'type' => 'trigger', 'x' => 0, 'y' => 0, 'data' => []], ['id' => 'm', 'type' => 'text', 'x' => 0, 'y' => 0, 'data' => ['text' => 'Bot here']]], 'edges' => [['from' => 's', 'port' => 'out', 'to' => 'm']]]]);

        $this->say('hi');
        $sent = $this->sent();
        $menu = collect($sent)->firstWhere('type', 'interactive');
        $this->assertSame(['ar:'.$rule->id.':b1', 'ar:'.$rule->id.':b2'], array_column(array_column($menu['interactive']['action']['buttons'], 'reply'), 'id'));
        $this->assertContains('Bot here', $this->texts()); // both systems answered the first message — independently

        Http::fake([$this->graph($this->account->phone_number_id.'/messages*') => $this->metaSendOkEachTime()]);
        $before = count($this->sent());
        $this->say('ar:'.$rule->id.':b2', 'tap');

        $this->assertSame(['An agent will reply soon.'], array_slice($this->texts(), $before));
        $this->assertSame(2, $rule->fresh()->runs_count);
    }

    public function test_away_message_outside_opening_hours_with_cooldown(): void
    {
        $this->rule(['trigger' => 'always', 'hours_mode' => 'outside', 'days' => [1, 2, 3, 4, 5], 'from_time' => '09:00', 'to_time' => '18:00',
            'cooldown_minutes' => 60, 'reply_text' => "We're closed now."]);

        $this->travelTo(Carbon::parse('2026-11-25 11:00')); // Wednesday, open
        $this->say('hi');
        $this->travelTo(Carbon::parse('2026-11-25 20:00')); // closed
        $this->say('hello?');
        $this->travelTo(Carbon::parse('2026-11-25 20:30')); // closed, but cooldown
        $this->say('anyone?');
        $this->travelTo(Carbon::parse('2026-11-29 12:00')); // Sunday — not an opening day
        $this->say('weekend');

        $this->assertSame(["We're closed now.", "We're closed now."], $this->texts());
    }

    public function test_inactive_rules_blocked_contacts_and_plans_without_automation_get_nothing(): void
    {
        $this->rule(['is_active' => false]);
        $this->say('hi');
        $this->assertSame([], $this->texts());

        AutoReply::query()->update(['is_active' => true]);
        Contact::query()->update(['blocked_at' => now()]);
        $this->say('hi again');
        $this->assertSame([], $this->texts());

        Contact::query()->update(['blocked_at' => null]);
        $plan = Plan::create(['name' => 'Basic', 'slug' => 'basic', 'price' => 0, 'currency' => 'PKR', 'billing_period' => 'free', 'is_active' => true, 'allow_bot_flows' => false]);
        $owner = User::factory()->withRole('Customer')->create(['plan_id' => $plan->id]);
        $this->account->forceFill(['owner_id' => $owner->id])->save();
        $this->say('third');
        $this->assertSame([], $this->texts());
    }

    public function test_manage_auto_replies_from_the_dashboard(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/auto-replies?account_id='.$this->account->id)->assertOk()->assertSee('Auto Replies')->assertSee('No auto replies for this number');
        $this->actingAs($admin)->get('/auto-replies/create?account_id='.$this->account->id.'&preset=away')->assertOk()->assertSee('Away message')->assertSee('autoReplyForm', false);

        $post = fn (array $data) => $this->actingAs($admin)->post('/auto-replies', array_merge([
            'whatsapp_account_id' => $this->account->id, 'name' => 'Welcome', 'trigger' => 'first_message', 'match_type' => 'contains',
            'hours_mode' => 'always', 'reply_text' => 'Hi!', 'is_active' => '1', 'while_chatting' => '0',
        ], $data));

        $post(['buttons' => [['title' => 'A', 'reply' => 'x'], ['title' => 'B', 'reply' => 'y'], ['title' => 'C', 'reply' => 'z'], ['title' => 'D', 'reply' => 'w']]])
            ->assertSessionHasErrors('buttons');
        $post(['buttons' => [['title' => 'A very long button title', 'reply' => 'x']]])->assertSessionHasErrors('buttons.0.title');
        $post(['trigger' => 'keyword', 'keywords' => ''])->assertSessionHasErrors('keywords');
        $post(['hours_mode' => 'outside', 'days' => [], 'from_time' => '09:00', 'to_time' => '18:00'])->assertSessionHasErrors('days');
        $post(['message_types' => ['fax']])->assertSessionHasErrors('message_types.0');

        $post([
            'message_types' => ['voice', 'image'],
            'buttons' => [['title' => 'Prices', 'reply' => 'Our prices'], ['title' => '', 'reply' => '']], // empty row is dropped
        ])->assertRedirect('/auto-replies?account_id='.$this->account->id)->assertSessionHas('success');

        $rule = AutoReply::firstOrFail();
        $this->assertSame(['voice', 'image'], $rule->message_types);
        $this->assertCount(1, $rule->buttons);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{6}$/', $rule->buttons[0]['id']);

        $this->actingAs($admin)->get('/auto-replies?account_id='.$this->account->id)->assertSee('Welcome')->assertSee('Prices')->assertSee('voice note / audio, picture');
        $this->actingAs($admin)->put("/auto-replies/{$rule->id}", [
            'name' => 'Welcome 2', 'trigger' => 'returning', 'gap_hours' => 12, 'match_type' => 'contains', 'hours_mode' => 'always',
            'reply_text' => 'Hi again', 'is_active' => '1', 'buttons' => [['id' => $rule->buttons[0]['id'], 'title' => 'Prices', 'reply' => 'New prices']],
        ])->assertRedirect();
        $rule->refresh();
        $this->assertSame(['returning', 12, 'New prices'], [$rule->trigger, $rule->gap_hours, $rule->buttons[0]['reply']]);

        $this->actingAs($admin)->patch("/auto-replies/{$rule->id}/toggle")->assertRedirect();
        $this->assertFalse($rule->fresh()->is_active);

        // An agent without the bots permission can't manage them; a number you can't access → 404.
        $this->actingAs($this->agent())->get('/auto-replies')->assertForbidden();
        $other = WhatsAppAccount::factory()->create();
        $foreign = AutoReply::create(['whatsapp_account_id' => $other->id, 'name' => 'X', 'trigger' => 'always', 'reply_text' => 'x']);
        $this->actingAs($admin)->delete("/auto-replies/{$rule->id}")->assertRedirect();
        $this->assertModelMissing($rule);
        $this->actingAs($this->admin())->get("/auto-replies/{$foreign->id}/edit")->assertOk(); // admins see every number

        $this->actingAs($admin)->get('/dashboard')->assertSee('Auto Replies');
    }
}
