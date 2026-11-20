<?php

namespace Tests\Feature;

use App\Models\BotFlow;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tag;
use App\Models\WhatsAppAccount;
use App\Services\WhatsApp\BotEngine;
use App\Support\BotGraph;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesMeta;
use Tests\Concerns\SendsWebhooks;
use Tests\TestCase;

/** Visual bot flows: Question (wait for an answer) + Condition (if / else) nodes. */
class BotQuestionConditionTest extends TestCase
{
    use FakesMeta, RefreshDatabase, SendsWebhooks;

    private WhatsAppAccount $account;

    private int $wamid = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = WhatsAppAccount::factory()->connected()->create();
        Http::fake([$this->graph($this->account->phone_number_id.'/messages*') => $this->metaSendOkEachTime()]);
    }

    private function node(string $id, string $type, array $data = []): array
    {
        return ['id' => $id, 'type' => $type, 'x' => 0, 'y' => 0, 'data' => $data];
    }

    private function edge(string $from, string $to, string $port = 'out'): array
    {
        return ['from' => $from, 'port' => $port, 'to' => $to];
    }

    private function flow(array $nodes, array $edges, array $attributes = []): BotFlow
    {
        return BotFlow::create(array_merge([
            'whatsapp_account_id' => $this->account->id, 'name' => 'Flow', 'trigger_type' => 'keyword', 'match_type' => 'contains',
            'keywords' => 'start', 'is_active' => true, 'steps' => [],
            'graph' => ['nodes' => [$this->node('start', 'trigger'), ...$nodes], 'edges' => $edges],
        ], $attributes));
    }

    private function say(string $text): void
    {
        $this->postWebhook($this->account, $this->textPayload($this->account, $text, 'wamid.Q'.(++$this->wamid)))->assertOk();
    }

    /** @return list<string> text bodies sent by the bot, in order */
    private function replies(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $r) => str_contains($r->url(), '/messages') && ($r['type'] ?? null) === 'text')
            ->map(fn (Request $r) => $r['text']['body'])->values()->all();
    }

    private function rule(string $id, array $rule): array
    {
        return ['id' => $id] + $rule;
    }

    public function test_question_waits_saves_the_answer_and_uses_it_later(): void
    {
        $this->flow([
            $this->node('ask', 'question', ['text' => 'What is your name?', 'var' => 'name', 'validate' => 'any', 'attempts' => 2, 'save_to' => 'name']),
            $this->node('thanks', 'text', ['text' => 'Thanks {{name}}! We will call {{phone}}.']),
        ], [$this->edge('start', 'ask'), $this->edge('ask', 'thanks')]);

        $this->say('start');
        $this->assertSame(['What is your name?'], $this->replies());
        $this->assertSame('ask', Conversation::firstOrFail()->bot_state['waiting']['node']);

        // The next message is the answer — even though it would match the keyword "start".
        $this->say('Ali start');
        $contact = Contact::firstOrFail();
        $this->assertSame('Thanks Ali start! We will call '.$contact->phone.'.', $this->replies()[1]);
        $this->assertSame('Ali start', $contact->name);

        $state = Conversation::firstOrFail()->bot_state;
        $this->assertNull($state['waiting']);
        $this->assertSame('Ali start', $state['vars']['name']);
    }

    public function test_invalid_answers_are_asked_again_then_take_the_invalid_path(): void
    {
        $this->flow([
            $this->node('ask', 'question', ['text' => 'Your email?', 'var' => 'email', 'validate' => 'email', 'retry_text' => 'That is not an email, try again.', 'attempts' => 2, 'save_to' => 'email']),
            $this->node('ok', 'text', ['text' => 'Saved {{email}}']),
            $this->node('bad', 'text', ['text' => 'No problem, an agent will help you.']),
        ], [$this->edge('start', 'ask'), $this->edge('ask', 'ok'), $this->edge('ask', 'bad', 'invalid')]);

        // Valid on the second try.
        $this->say('start');
        $this->say('my email is nothing');
        $this->say('ali@example.com');
        $this->assertSame(['Your email?', 'That is not an email, try again.', 'Saved ali@example.com'], $this->replies());
        $this->assertSame('ali@example.com', Contact::firstOrFail()->email);

        // Two wrong answers → "No valid answer" path.
        $this->say('start');
        $this->say('nope');
        $this->say('still nope');
        $this->assertSame(['Your email?', 'That is not an email, try again.', 'No problem, an agent will help you.'], array_slice($this->replies(), 3));
        $this->assertNull(Conversation::firstOrFail()->bot_state['waiting']);
    }

    public function test_condition_routes_by_the_saved_answer(): void
    {
        $this->flow([
            $this->node('ask', 'question', ['text' => 'Order or complaint?', 'var' => 'topic', 'validate' => 'any', 'attempts' => 2]),
            $this->node('check', 'condition', ['rules' => [
                $this->rule('r1', ['kind' => 'text', 'subject' => 'var', 'var' => 'topic', 'op' => 'contains', 'value' => 'order, buy, khareed']),
                $this->rule('r2', ['kind' => 'text', 'subject' => 'var', 'var' => 'topic', 'op' => 'contains', 'value' => 'complain, shikayat']),
            ]]),
            $this->node('order', 'text', ['text' => 'Sales team']),
            $this->node('complaint', 'text', ['text' => 'Support team']),
            $this->node('other', 'text', ['text' => 'Sorry, I did not get that']),
        ], [
            $this->edge('start', 'ask'), $this->edge('ask', 'check'),
            $this->edge('check', 'order', 'r1'), $this->edge('check', 'complaint', 'r2'), $this->edge('check', 'other', 'else'),
        ]);

        foreach (['I want to BUY a plan' => 'Sales team', 'meri shikayat hai' => 'Support team', 'hello' => 'Sorry, I did not get that'] as $answer => $expected) {
            $this->say('start');
            $this->say($answer);
            $this->assertSame($expected, last($this->replies()), "Answer: {$answer}");
        }
    }

    public function test_condition_on_business_hours_tags_new_contacts_and_numbers(): void
    {
        $engine = app(BotEngine::class);
        $this->say('hello'); // creates contact + conversation (no flows yet)
        $conversation = Conversation::firstOrFail();
        $message = $conversation->messages()->firstOrFail();

        // Business hours Mon–Fri 09:00–18:00 (app time zone).
        $hours = ['kind' => 'time', 'days' => [1, 2, 3, 4, 5], 'from' => '09:00', 'to' => '18:00'];
        Carbon::setTestNow(Carbon::parse('next monday 10:30'));
        $this->assertTrue($engine->ruleMatches($hours, $conversation, $message, []));
        Carbon::setTestNow(Carbon::parse('next monday 19:00'));
        $this->assertFalse($engine->ruleMatches($hours, $conversation, $message, []));
        Carbon::setTestNow(Carbon::parse('next sunday 10:30'));
        $this->assertFalse($engine->ruleMatches($hours, $conversation, $message, []));
        Carbon::setTestNow(Carbon::parse('next monday 23:30'));
        $this->assertTrue($engine->ruleMatches(['kind' => 'time', 'days' => [1], 'from' => '22:00', 'to' => '06:00'], $conversation, $message, []));
        Carbon::setTestNow();

        // Tags (case-insensitive).
        $tag = Tag::create(['whatsapp_account_id' => $this->account->id, 'name' => 'VIP', 'color' => 'amber']);
        $this->assertFalse($engine->ruleMatches(['kind' => 'tag', 'op' => 'has', 'value' => 'vip'], $conversation, $message, []));
        $conversation->contact->tags()->attach($tag);
        $this->assertTrue($engine->ruleMatches(['kind' => 'tag', 'op' => 'has', 'value' => 'vip'], $conversation->fresh(), $message, []));
        $this->assertFalse($engine->ruleMatches(['kind' => 'tag', 'op' => 'has_not', 'value' => 'VIP'], $conversation->fresh(), $message, []));

        // New vs returning contact.
        $this->assertTrue($engine->ruleMatches(['kind' => 'new_contact', 'op' => 'is'], $conversation, $message, []));
        $conversation->contact->forceFill(['created_at' => now()->subDays(3)])->save();
        $this->assertTrue($engine->ruleMatches(['kind' => 'new_contact', 'op' => 'is_not'], $conversation->fresh(), $message, []));

        // Numbers and empty checks on saved answers.
        $vars = ['qty' => '1,500', 'note' => ''];
        $this->assertTrue($engine->ruleMatches(['kind' => 'text', 'subject' => 'var', 'var' => 'qty', 'op' => 'number_gt', 'value' => '1000'], $conversation, $message, $vars));
        $this->assertFalse($engine->ruleMatches(['kind' => 'text', 'subject' => 'var', 'var' => 'qty', 'op' => 'number_lt', 'value' => '1000'], $conversation, $message, $vars));
        $this->assertTrue($engine->ruleMatches(['kind' => 'text', 'subject' => 'var', 'var' => 'note', 'op' => 'is_empty', 'value' => ''], $conversation, $message, $vars));
        $this->assertTrue($engine->ruleMatches(['kind' => 'text', 'subject' => 'message', 'op' => 'equals', 'value' => 'hi, HELLO'], $conversation, $message, $vars));
        $this->assertTrue($engine->ruleMatches(['kind' => 'text', 'subject' => 'message', 'op' => 'not_contains', 'value' => 'price'], $conversation, $message, $vars));
    }

    public function test_condition_in_a_flow_uses_the_incoming_message_and_sends_nothing_itself(): void
    {
        $this->flow([
            $this->node('check', 'condition', ['rules' => [$this->rule('urdu', ['kind' => 'text', 'subject' => 'message', 'op' => 'contains', 'value' => 'salam, assalam'])]]),
            $this->node('ur', 'text', ['text' => 'Walaikum assalam!']),
            $this->node('en', 'text', ['text' => 'Hello!']),
        ], [$this->edge('start', 'check'), $this->edge('check', 'ur', 'urdu'), $this->edge('check', 'en', 'else')], ['trigger_type' => 'fallback', 'keywords' => null]);

        $this->say('Assalam o alaikum');
        $this->say('hi there');
        $this->assertSame(['Walaikum assalam!', 'Hello!'], $this->replies());
    }

    public function test_menu_tap_cancels_a_pending_question_and_old_questions_expire(): void
    {
        $menuFlow = $this->flow([
            $this->node('m', 'menu', ['text' => 'Choose', 'options' => [['id' => 'o1', 'title' => 'Talk', 'description' => '']]]),
            $this->node('talk', 'text', ['text' => 'Talking!']),
        ], [$this->edge('start', 'm'), $this->edge('m', 'talk', 'o1')], ['keywords' => 'menu']);
        $this->flow([$this->node('ask', 'question', ['text' => 'Name?', 'var' => 'name', 'validate' => 'any', 'attempts' => 2])], [$this->edge('start', 'ask')], ['keywords' => 'ask']);

        $this->say('menu');
        $this->say('ask'); // now waiting for a name
        $this->postWebhook($this->account, $this->incomingPayload($this->account, [
            'id' => 'wamid.TAP', 'type' => 'interactive',
            'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => "bf:{$menuFlow->id}:m:o1", 'title' => 'Talk']],
        ]))->assertOk();
        $this->assertSame(['Name?', 'Talking!'], $this->replies());
        $this->assertNull(Conversation::firstOrFail()->bot_state['waiting']);

        // A question older than the answer timeout no longer captures messages.
        $this->say('ask');
        $this->travel((int) config('whatsapp.bot.answer_timeout_minutes') + 1)->minutes();
        $this->say('menu');
        $this->assertNull(Conversation::firstOrFail()->bot_state['vars']['name'] ?? null);
        $this->assertSame(2, collect(Http::recorded())->filter(fn ($p) => ($p[0]['type'] ?? null) === 'interactive')->count()); // "menu" ran again
        $this->assertNull(Conversation::firstOrFail()->bot_state['waiting']);
    }

    public function test_agent_reply_clears_the_pending_question(): void
    {
        $this->flow([$this->node('ask', 'question', ['text' => 'Name?', 'var' => 'name', 'validate' => 'any', 'attempts' => 2])], [$this->edge('start', 'ask')]);
        $this->say('start');
        $conversation = Conversation::firstOrFail();
        $this->assertNotNull($conversation->bot_state['waiting']);

        $this->actingAs($this->admin())->postJson("/chat/conversations/{$conversation->id}/messages", ['body' => 'Hi, I am Sara from the team'])->assertCreated();

        $this->assertNull($conversation->fresh()->bot_state);
    }

    /** The exact graph saved from the canvas during the browser test (Trigger → Message → Question → Condition → 3 paths). */
    public function test_flow_built_in_the_canvas_runs_end_to_end(): void
    {
        $graph = json_decode(file_get_contents(base_path('tests/Fixtures/canvas-question-condition.json')), true);
        $this->flow([], [], ['keywords' => 'qatest', 'graph' => BotGraph::validate($graph, $this->account)]);

        // Rule 1: saved answer contains "order".
        $this->say('qatest');
        $this->say('Mujhe ORDER karna hai');
        $this->assertSame([
            'Assalam o alaikum!',
            'Aap kya chahte hain? Order ya complaint?',
            'Order: hamari sales team aap se rabta karegi. (Mujhe ORDER karna hai)',
        ], $this->replies());

        // Rule 2: business hours (Mon–Sat 09:00–18:00).
        $this->travelTo(Carbon::parse('next monday 11:00'));
        $this->say('qatest');
        $this->say('kuch aur');
        $this->assertSame('Hamari team abhi online hai, jaldi jawab milega.', last($this->replies()));

        // Otherwise: Sunday → handoff, bot paused.
        $this->travelTo(Carbon::parse('next sunday 11:00'));
        $this->say('qatest');
        $this->say('kuch aur');
        $this->assertSame('Connecting you to our team — please wait a moment.', last($this->replies()));
        $this->assertTrue(Conversation::firstOrFail()->isBotPaused());
    }

    public function test_builder_validates_question_and_condition_nodes(): void
    {
        $admin = $this->admin();
        $post = fn (array $nodes, array $edges) => $this->actingAs($admin)->postJson('/bot-flows', [
            'whatsapp_account_id' => $this->account->id, 'name' => 'Q', 'trigger_type' => 'keyword', 'match_type' => 'contains',
            'keywords' => 'x', 'is_active' => 1, 'graph' => json_encode(['nodes' => [$this->node('start', 'trigger'), ...$nodes], 'edges' => $edges]),
        ]);

        $res = $post([
            $this->node('q', 'question', ['text' => '', 'var' => 'Bad Name!', 'validate' => 'email']),
            $this->node('c', 'condition', ['rules' => [
                $this->rule('r1', ['kind' => 'text', 'subject' => 'var', 'var' => '', 'op' => 'contains', 'value' => '']),
                $this->rule('r2', ['kind' => 'time', 'days' => [], 'from' => '25:00', 'to' => '18:00']),
                $this->rule('r3', ['kind' => 'tag', 'op' => 'has', 'value' => '']),
                $this->rule('r4', ['kind' => 'text', 'subject' => 'message', 'op' => 'number_gt', 'value' => 'abc']),
            ]]),
        ], [$this->edge('start', 'q'), $this->edge('q', 'c')])->assertStatus(422);

        $errors = implode(' | ', $res->json('errors.graph'));
        foreach (['Question 1: the question is required', 'Question 1: save the answer as a variable', 'Condition 1, rule 1: choose which saved answer',
            'Condition 1, rule 1: enter the value', 'Condition 1, rule 2: pick at least one day', 'Condition 1, rule 3: enter the tag name',
            'Condition 1, rule 4: the value must be a number'] as $expected) {
            $this->assertStringContainsString($expected, $errors);
        }

        // A good one saves, keeps rule ports and drops unknown ports.
        $res = $post([
            $this->node('q', 'question', ['text' => 'Email?', 'var' => 'email', 'validate' => 'email', 'attempts' => 9]),
            $this->node('c', 'condition', ['rules' => [$this->rule('vip', ['kind' => 'tag', 'op' => 'has', 'value' => 'VIP'])]]),
            $this->node('a', 'text', ['text' => 'VIP']),
            $this->node('b', 'text', ['text' => 'Normal']),
        ], [
            $this->edge('start', 'q'), $this->edge('q', 'c'), $this->edge('q', 'b', 'invalid'),
            $this->edge('c', 'a', 'vip'), $this->edge('c', 'b', 'else'), $this->edge('c', 'a', 'nope'),
        ])->assertCreated();

        $flow = BotFlow::findOrFail($res->json('id'));
        $this->assertCount(5, $flow->graph['edges']);
        $this->assertSame(5, collect($flow->graph['nodes'])->firstWhere('id', 'q')['data']['attempts']); // clamped to 5
    }
}
