<?php

namespace Tests\Feature;

use App\Jobs\DownloadMessageMedia;
use App\Models\BotFlow;
use App\Models\WhatsAppAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesMeta;
use Tests\Concerns\SendsWebhooks;
use Tests\TestCase;

/** "Any message" trigger: the flow runs for every incoming message of any type. */
class BotAnyMessageTest extends TestCase
{
    use FakesMeta, RefreshDatabase, SendsWebhooks;

    private WhatsAppAccount $account;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([DownloadMessageMedia::class]); // media download is not part of these tests
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

    private function flow(string $trigger, array $nodes, array $edges, array $attributes = []): BotFlow
    {
        return BotFlow::create(array_merge([
            'whatsapp_account_id' => $this->account->id, 'name' => ucfirst($trigger), 'trigger_type' => $trigger, 'match_type' => 'contains',
            'keywords' => $trigger === 'keyword' ? 'price' : null, 'priority' => 0, 'cooldown_minutes' => 0, 'is_active' => true, 'steps' => [],
            'graph' => ['nodes' => [$this->node('start', 'trigger'), ...$nodes], 'edges' => $edges],
        ], $attributes));
    }

    /** One-message flow: Trigger → Message. */
    private function replyFlow(string $trigger, string $text, array $attributes = []): BotFlow
    {
        return $this->flow($trigger, [$this->node('m', 'text', ['text' => $text])], [$this->edge('start', 'm')], $attributes);
    }

    /** Customer sends one message of the given type. */
    private function send(string $type, string $text = 'hello'): void
    {
        $id = 'wamid.ANY'.(++$this->n);
        $message = match ($type) {
            'text' => ['type' => 'text', 'text' => ['body' => $text]],
            'voice' => ['type' => 'audio', 'audio' => ['id' => 'M'.$this->n, 'mime_type' => 'audio/ogg; codecs=opus', 'voice' => true]],
            'image' => ['type' => 'image', 'image' => ['id' => 'M'.$this->n, 'mime_type' => 'image/jpeg', 'caption' => '']],
            'video' => ['type' => 'video', 'video' => ['id' => 'M'.$this->n, 'mime_type' => 'video/mp4']],
            'document' => ['type' => 'document', 'document' => ['id' => 'M'.$this->n, 'mime_type' => 'application/pdf', 'filename' => 'cv.pdf']],
            'sticker' => ['type' => 'sticker', 'sticker' => ['id' => 'M'.$this->n, 'mime_type' => 'image/webp']],
            'location' => ['type' => 'location', 'location' => ['latitude' => 31.52, 'longitude' => 74.35, 'name' => 'Shop']],
        };

        $this->postWebhook($this->account, $this->incomingPayload($this->account, ['id' => $id, 'timestamp' => (string) (now()->timestamp + $this->n)] + $message))->assertOk();
    }

    /** @return list<string> text replies sent by the bot, in order */
    private function replies(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $r) => str_contains($r->url(), '/messages') && ($r['type'] ?? null) === 'text')
            ->map(fn (Request $r) => $r['text']['body'])->values()->all();
    }

    public function test_runs_for_every_message_of_every_type_first_or_not_keyword_or_not(): void
    {
        $this->replyFlow('any', 'Got it ✅');
        $this->replyFlow('keyword', 'Our prices…', ['priority' => 100]);
        $this->replyFlow('welcome', 'Welcome!');
        $this->replyFlow('fallback', 'Sorry?');

        $types = ['text', 'voice', 'image', 'video', 'document', 'sticker', 'location'];
        foreach ($types as $type) {
            $this->send($type);
        }
        $this->send('text', 'price please'); // keyword matches — "any" still answers

        $this->assertSame(array_fill(0, count($types) + 1, 'Got it ✅'), $this->replies());
        $this->assertSame(8, BotFlow::where('trigger_type', 'any')->value('runs_count'));
    }

    public function test_condition_message_type_gives_a_different_reply_per_type(): void
    {
        $this->flow('any', [
            $this->node('c', 'condition', ['rules' => [
                ['id' => 'v', 'kind' => 'message_type', 'op' => 'is', 'mtype' => 'voice'],
                ['id' => 'p', 'kind' => 'message_type', 'op' => 'is', 'mtype' => 'image'],
                ['id' => 'm', 'kind' => 'message_type', 'op' => 'is', 'mtype' => 'media'],
            ]]),
            $this->node('voice', 'text', ['text' => 'Thanks for the voice note 🎤']),
            $this->node('pic', 'text', ['text' => 'Nice picture 📷']),
            $this->node('media', 'text', ['text' => 'File received 📎']),
            $this->node('text', 'text', ['text' => 'Thanks for your message']),
        ], [
            $this->edge('start', 'c'),
            $this->edge('c', 'voice', 'v'), $this->edge('c', 'pic', 'p'), $this->edge('c', 'media', 'm'), $this->edge('c', 'text', 'else'),
        ]);

        foreach (['text', 'voice', 'image', 'document', 'video'] as $type) {
            $this->send($type);
        }

        $this->assertSame([
            'Thanks for your message', 'Thanks for the voice note 🎤', 'Nice picture 📷', 'File received 📎', 'File received 📎',
        ], $this->replies());
    }

    public function test_question_answers_and_menu_taps_still_continue_their_own_flow(): void
    {
        $this->flow('any', [
            $this->node('ask', 'question', ['text' => 'Your name?', 'var' => 'name', 'validate' => 'any', 'attempts' => 2, 'save_to' => 'none']),
            $this->node('thanks', 'text', ['text' => 'Thanks {{name}}!']),
        ], [$this->edge('start', 'ask'), $this->edge('ask', 'thanks')]);

        $this->send('text', 'hi');
        $this->send('text', 'Ali');       // the answer — not a new start
        $this->send('image');             // a new message → starts again

        $this->assertSame(['Your name?', 'Thanks Ali!', 'Your name?'], $this->replies());
    }

    public function test_cooldown_lets_the_other_flows_answer_in_between(): void
    {
        $this->replyFlow('any', 'We got your message, an agent will reply soon.', ['cooldown_minutes' => 60]);
        $this->replyFlow('keyword', 'Our prices…');
        $this->replyFlow('fallback', 'Type price for prices.');

        $this->send('voice');
        $this->send('text', 'price');
        $this->send('text', 'ok');
        $this->travel(61)->minutes();
        $this->send('image');

        $this->assertSame([
            'We got your message, an agent will reply soon.', 'Our prices…', 'Type price for prices.', 'We got your message, an agent will reply soon.',
        ], $this->replies());
    }

    public function test_builder_saves_the_trigger_and_checks_message_type_rules(): void
    {
        $admin = $this->admin();
        $graph = fn (string $mtype) => json_encode([
            'nodes' => [
                $this->node('start', 'trigger'),
                $this->node('c', 'condition', ['rules' => [['id' => 'r1', 'kind' => 'message_type', 'op' => 'is_not', 'mtype' => $mtype]]]),
                $this->node('t', 'text', ['text' => 'Hi']),
            ],
            'edges' => [$this->edge('start', 'c'), $this->edge('c', 't', 'r1')],
        ]);
        $payload = fn (string $mtype) => [
            'whatsapp_account_id' => $this->account->id, 'name' => 'Every message', 'trigger_type' => 'any', 'match_type' => 'contains',
            'is_active' => 1, 'priority' => 0, 'cooldown_minutes' => 0, 'graph' => $graph($mtype),
        ];

        $this->actingAs($admin)->postJson('/bot-flows', $payload('fax'))->assertUnprocessable()->assertJsonPath('message', 'Condition 1, rule 1: choose the message type.');

        $id = $this->actingAs($admin)->postJson('/bot-flows', $payload('voice'))->assertCreated()->json('id');
        $flow = BotFlow::findOrFail($id);
        $this->assertSame('any', $flow->trigger_type);
        $this->assertSame(['id' => 'r1', 'kind' => 'message_type', 'op' => 'is_not', 'mtype' => 'voice'], $flow->graph['nodes'][1]['data']['rules'][0]);

        $this->actingAs($admin)->get("/bot-flows/{$id}/edit")->assertOk()->assertSee('Any message (every message, any type)')->assertSee('Message type (voice, picture…)');
        $this->actingAs($admin)->get('/bot-flows?account_id='.$this->account->id)->assertSee('Any message (every message, any type)');
    }
}
