<?php

namespace Tests\Feature;

use App\Models\BotFlow;
use App\Models\Conversation;
use App\Models\WhatsAppAccount;
use App\Support\BotGraph;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesMeta;
use Tests\Concerns\SendsWebhooks;
use Tests\TestCase;

/** n8n-style visual bot flows: nodes + connections. */
class VisualBotFlowTest extends TestCase
{
    use FakesMeta, RefreshDatabase, SendsWebhooks;

    private WhatsAppAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = WhatsAppAccount::factory()->connected()->create();
        Http::fake([$this->graph($this->account->phone_number_id.'/messages*') => $this->metaSendOkEachTime()]);
    }

    private function node(string $id, string $type, array $data = [], int $x = 0, int $y = 0): array
    {
        return ['id' => $id, 'type' => $type, 'x' => $x, 'y' => $y, 'data' => $data];
    }

    private function edge(string $from, string $to, string $port = 'out'): array
    {
        return ['from' => $from, 'port' => $port, 'to' => $to];
    }

    private function visualFlow(array $nodes, array $edges, array $attributes = []): BotFlow
    {
        return BotFlow::create(array_merge([
            'whatsapp_account_id' => $this->account->id, 'name' => 'Visual', 'trigger_type' => 'keyword',
            'match_type' => 'contains', 'keywords' => 'menu', 'priority' => 0, 'cooldown_minutes' => 0,
            'is_active' => true, 'steps' => [],
            'graph' => ['nodes' => [$this->node('start', 'trigger'), ...$nodes], 'edges' => $edges],
        ], $attributes));
    }

    /** Main menu: welcome text → menu (Sales → text, Support → handoff, Prices → image → text). */
    private function mainMenu(): BotFlow
    {
        return $this->visualFlow([
            $this->node('hello', 'text', ['text' => 'Welcome to KN Softic!']),
            $this->node('menu', 'menu', ['text' => 'How can we help?', 'footer' => '', 'button_label' => '', 'options' => [
                ['id' => 'sales', 'title' => 'Sales', 'description' => ''],
                ['id' => 'support', 'title' => 'Support', 'description' => ''],
                ['id' => 'prices', 'title' => 'Price list', 'description' => ''],
            ]]),
            $this->node('salesMsg', 'text', ['text' => 'Our sales team will call you.']),
            $this->node('handoff', 'handoff', ['text' => 'Connecting you to support.']),
            $this->node('pic', 'image', ['url' => 'https://example.com/prices.png', 'caption' => 'Prices']),
            $this->node('after', 'text', ['text' => 'Anything else? Type menu.']),
        ], [
            $this->edge('start', 'hello'),
            $this->edge('hello', 'menu'),
            $this->edge('menu', 'salesMsg', 'sales'),
            $this->edge('menu', 'handoff', 'support'),
            $this->edge('menu', 'pic', 'prices'),
            $this->edge('pic', 'after'),
        ]);
    }

    private function sentBodies(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $r) => str_contains($r->url(), '/messages') && ($r['type'] ?? null) !== null)
            ->map(fn (Request $r) => $r->data())->values()->all();
    }

    private function tap(string $id, string $title, string $wamid): void
    {
        $this->postWebhook($this->account, $this->incomingPayload($this->account, [
            'id' => $wamid, 'type' => 'interactive',
            'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $id, 'title' => $title]],
        ]))->assertOk();
    }

    public function test_flow_follows_the_connections_and_each_menu_option_has_its_own_path(): void
    {
        $flow = $this->mainMenu();

        // Keyword → welcome text, then the menu; the bot stops and waits at the menu.
        $this->postWebhook($this->account, $this->textPayload($this->account, 'show me the menu', 'wamid.V1'))->assertOk();
        $sent = $this->sentBodies();
        $this->assertCount(2, $sent);
        $this->assertSame('Welcome to KN Softic!', $sent[0]['text']['body']);
        $buttons = $sent[1]['interactive']['action']['buttons'];
        $this->assertSame(["bf:{$flow->id}:menu:sales", "bf:{$flow->id}:menu:support", "bf:{$flow->id}:menu:prices"], array_column(array_column($buttons, 'reply'), 'id'));

        // "Price list" → image → follow-up text (only that branch).
        $this->tap("bf:{$flow->id}:menu:prices", 'Price list', 'wamid.V2');
        $sent = $this->sentBodies();
        $this->assertCount(4, $sent);
        $this->assertSame('https://example.com/prices.png', $sent[2]['image']['link']);
        $this->assertSame('Anything else? Type menu.', $sent[3]['text']['body']);

        // "Sales" → its own message.
        $this->tap("bf:{$flow->id}:menu:sales", 'Sales', 'wamid.V3');
        $this->assertSame('Our sales team will call you.', $this->sentBodies()[4]['text']['body']);

        // "Support" → handoff text, bot paused.
        $this->tap("bf:{$flow->id}:menu:support", 'Support', 'wamid.V4');
        $this->assertSame('Connecting you to support.', $this->sentBodies()[5]['text']['body']);
        $this->assertTrue(Conversation::firstOrFail()->isBotPaused());
        $this->assertCount(6, $this->sentBodies());
    }

    public function test_go_to_flow_node_runs_the_other_flow(): void
    {
        $sales = $this->visualFlow([$this->node('t', 'text', ['text' => 'Sales flow here!'])], [$this->edge('start', 't')], ['name' => 'Sales', 'trigger_type' => 'menu', 'keywords' => null]);

        // Option pointing straight at "Go to flow" uses the short "bf:{flow}" id.
        $menu = $this->visualFlow([
            $this->node('m', 'menu', ['text' => 'Pick', 'footer' => '', 'button_label' => '', 'options' => [['id' => 'o1', 'title' => 'Sales', 'description' => '']]]),
            $this->node('go', 'goto', ['flow_id' => $sales->id]),
        ], [$this->edge('start', 'm'), $this->edge('m', 'go', 'o1')]);

        $this->postWebhook($this->account, $this->textPayload($this->account, 'menu', 'wamid.G1'));
        $this->assertSame('bf:'.$sales->id, $this->sentBodies()[0]['interactive']['action']['buttons'][0]['reply']['id']);

        $this->tap('bf:'.$sales->id, 'Sales', 'wamid.G2');
        $this->assertSame('Sales flow here!', $this->sentBodies()[1]['text']['body']);

        // A message → Go to flow chain inside one run.
        $menu->update(['keywords' => 'nothing-matches']);
        $this->visualFlow([
            $this->node('a', 'text', ['text' => 'One moment…']),
            $this->node('go', 'goto', ['flow_id' => $sales->id]),
        ], [$this->edge('start', 'a'), $this->edge('a', 'go')], ['keywords' => 'hello']);

        $this->postWebhook($this->account, $this->textPayload($this->account, 'hello', 'wamid.G3'));
        $sent = $this->sentBodies();
        $this->assertSame('One moment…', $sent[2]['text']['body']);
        $this->assertSame('Sales flow here!', $sent[3]['text']['body']);
        $this->assertSame(2, $sales->fresh()->runs_count);
    }

    public function test_loops_stop_at_the_safety_limit(): void
    {
        $this->visualFlow([
            $this->node('a', 'text', ['text' => 'ping']),
            $this->node('b', 'text', ['text' => 'pong']),
        ], [$this->edge('start', 'a'), $this->edge('a', 'b'), $this->edge('b', 'a')]);

        $this->postWebhook($this->account, $this->textPayload($this->account, 'menu', 'wamid.L1'));

        $this->assertCount((int) config('whatsapp.bot.max_steps'), $this->sentBodies());
    }

    public function test_tapping_a_menu_option_ignores_the_cooldown(): void
    {
        $flow = $this->mainMenu();
        $flow->update(['cooldown_minutes' => 60]);

        $this->postWebhook($this->account, $this->textPayload($this->account, 'menu', 'wamid.C1'));
        $this->postWebhook($this->account, $this->textPayload($this->account, 'menu again', 'wamid.C2'));
        $this->assertCount(2, $this->sentBodies()); // second keyword hit is in cooldown

        $this->tap("bf:{$flow->id}:menu:sales", 'Sales', 'wamid.C3');
        $this->assertCount(3, $this->sentBodies()); // button still answers
    }

    public function test_builder_saves_the_graph_as_json(): void
    {
        $admin = $this->admin();
        $graph = [
            'nodes' => [
                $this->node('start', 'trigger', [], 60, 200),
                $this->node('n1', 'text', ['text' => '  Hello there  '], 380, 200),
                $this->node('n2', 'menu', ['text' => 'Choose', 'footer' => '', 'button_label' => '', 'options' => [['id' => 'o1', 'title' => 'Talk to us', 'description' => '']]], 700, 200),
                $this->node('n3', 'handoff', ['text' => ''], 1000, 200),
            ],
            'edges' => [$this->edge('start', 'n1'), $this->edge('n1', 'n2'), $this->edge('n2', 'n3', 'o1'), ['from' => 'n3', 'port' => 'out', 'to' => 'n1']],
        ];

        $res = $this->actingAs($admin)->postJson('/bot-flows', [
            'whatsapp_account_id' => $this->account->id, 'name' => 'Canvas', 'trigger_type' => 'welcome', 'match_type' => 'contains',
            'is_active' => 1, 'priority' => 0, 'cooldown_minutes' => 0, 'graph' => json_encode($graph),
        ])->assertCreated()->assertJsonStructure(['message', 'id', 'redirect']);

        $flow = BotFlow::findOrFail($res->json('id'));
        $this->assertTrue($flow->isVisual());
        $this->assertSame([], $flow->steps);
        $this->assertSame('Hello there', BotGraph::node($flow->graph, 'n1')['data']['text']);
        $this->assertCount(3, $flow->graph['edges']); // impossible "handoff → …" connection dropped
        $this->assertSame(['x' => 700, 'y' => 200], array_intersect_key(BotGraph::node($flow->graph, 'n2'), ['x' => 1, 'y' => 1]));

        // Update keeps you on the canvas (JSON).
        $graph['nodes'][1]['data']['text'] = 'Changed';
        $this->actingAs($admin)->putJson("/bot-flows/{$flow->id}", [
            'name' => 'Canvas', 'trigger_type' => 'welcome', 'match_type' => 'contains', 'is_active' => 1, 'graph' => json_encode($graph),
        ])->assertOk()->assertJson(['message' => 'Saved.']);
        $this->assertSame('Changed', BotGraph::node($flow->fresh()->graph, 'n1')['data']['text']);

        $this->actingAs($admin)->get("/bot-flows/{$flow->id}/edit")->assertOk()->assertSee('botBuilder', false)->assertSee('Canvas');
        $this->actingAs($admin)->get('/bot-flows?account_id='.$this->account->id)->assertSee('Message → Menu (1) · 3 nodes');
    }

    public function test_builder_rejects_broken_flows_with_clear_messages(): void
    {
        $admin = $this->admin();
        $post = fn (array $nodes, array $edges) => $this->actingAs($admin)->postJson('/bot-flows', [
            'whatsapp_account_id' => $this->account->id, 'name' => 'Bad', 'trigger_type' => 'keyword', 'match_type' => 'contains',
            'keywords' => 'x', 'is_active' => 1, 'graph' => json_encode(['nodes' => [$this->node('start', 'trigger'), ...$nodes], 'edges' => $edges]),
        ]);

        // Menu option not connected.
        $post([$this->node('m', 'menu', ['text' => 'Pick', 'options' => [['id' => 'o1', 'title' => 'One']]])], [$this->edge('start', 'm')])
            ->assertStatus(422)->assertJsonPath('errors.graph.0', 'Menu 1, option “One”: connect it to the next node (drag from the dot next to the option).');

        // Trigger not connected + empty message + http image + button title too long.
        $res = $post([
            $this->node('t', 'text', ['text' => '']),
            $this->node('i', 'image', ['url' => 'http://insecure.test/a.png']),
            $this->node('m', 'menu', ['text' => 'Pick', 'options' => [['id' => 'o1', 'title' => str_repeat('a', 21)]]]),
            $this->node('h', 'handoff'),
        ], [$this->edge('m', 'h', 'o1')])->assertStatus(422);

        $errors = implode(' | ', $res->json('errors.graph'));
        $this->assertStringContainsString('Message 1: message text is required', $errors);
        $this->assertStringContainsString('Image 1: enter a public https:// link', $errors);
        $this->assertStringContainsString('max 20 characters for buttons', $errors);
        $this->assertStringContainsString('Connect the Trigger', $errors);

        // Go to flow must point to another flow of the same number.
        $other = WhatsAppAccount::factory()->connected()->create();
        $foreign = BotFlow::create(['whatsapp_account_id' => $other->id, 'name' => 'Foreign', 'trigger_type' => 'menu', 'match_type' => 'contains', 'steps' => [['type' => 'text', 'text' => 'x']], 'is_active' => true]);
        $post([$this->node('g', 'goto', ['flow_id' => $foreign->id])], [$this->edge('start', 'g')])
            ->assertStatus(422)->assertJsonPath('errors.graph.0', 'Go to flow 1: choose another flow of the same WhatsApp number.');

        $this->assertSame(1, BotFlow::count());
    }

    public function test_older_step_flows_open_in_the_canvas_and_still_run(): void
    {
        $target = BotFlow::create(['whatsapp_account_id' => $this->account->id, 'name' => 'Target', 'trigger_type' => 'menu', 'match_type' => 'contains', 'steps' => [['type' => 'text', 'text' => 'Target!']], 'is_active' => true]);
        $legacy = BotFlow::create([
            'whatsapp_account_id' => $this->account->id, 'name' => 'Old', 'trigger_type' => 'keyword', 'match_type' => 'contains', 'keywords' => 'old', 'is_active' => true,
            'steps' => [['type' => 'text', 'text' => 'Hi'], ['type' => 'menu', 'text' => 'Pick', 'options' => [['title' => 'Go', 'flow_id' => $target->id]]]],
        ]);

        $graph = $legacy->flowGraph();
        $this->assertFalse($legacy->isVisual());
        $this->assertEqualsCanonicalizing(['trigger', 'text', 'menu', 'goto'], array_column($graph['nodes'], 'type'));
        $this->assertSame('Message → Menu (1) · 3 nodes', BotGraph::summary($graph));
        $this->actingAs($this->admin())->get("/bot-flows/{$legacy->id}/edit")->assertOk()->assertSee('Pick');

        $this->postWebhook($this->account, $this->textPayload($this->account, 'old', 'wamid.O1'));
        $sent = $this->sentBodies();
        $this->assertSame('Hi', $sent[0]['text']['body']);
        $this->assertSame('bf:'.$target->id, $sent[1]['interactive']['action']['buttons'][0]['reply']['id']);
    }
}
