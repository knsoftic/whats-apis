<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Support\ChatCounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** Inbox counters: sidebar badge, chat filter tabs, number switcher, realtime check. */
class ChatCountsTest extends TestCase
{
    use RefreshDatabase;

    private WhatsAppAccount $a;

    private WhatsAppAccount $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = WhatsAppAccount::factory()->connected()->create(['name' => 'Alpha']);
        $this->b = WhatsAppAccount::factory()->connected()->create(['name' => 'Beta']);

        // Alpha: 2 unread chats (3 + 2 msgs), 1 of them waiting; 1 open answered; 1 closed.
        $this->chat($this->a, ['unread_count' => 3, 'last_message_direction' => Message::IN]);
        $this->chat($this->a, ['unread_count' => 2, 'last_message_direction' => Message::OUT]);
        $this->chat($this->a, ['last_message_direction' => Message::OUT]);
        $this->chat($this->a, ['status' => Conversation::STATUS_CLOSED, 'last_message_direction' => Message::IN]);
        // Beta: 1 unread waiting chat (4 msgs).
        $this->chat($this->b, ['unread_count' => 4, 'last_message_direction' => Message::IN]);
    }

    private function chat(WhatsAppAccount $account, array $attrs): Conversation
    {
        $contact = Contact::factory()->create(['whatsapp_account_id' => $account->id]);

        return Conversation::factory()->create(array_merge(['contact_id' => $contact->id, 'whatsapp_account_id' => $account->id], $attrs));
    }

    public function test_counts_per_number_and_in_total(): void
    {
        $counts = ChatCounts::forAccounts([$this->a->id, $this->b->id]);

        $this->assertSame(['all' => 4, 'unread' => 2, 'messages' => 5, 'waiting' => 1, 'open' => 3, 'closed' => 1], $counts['accounts'][$this->a->id]);
        $this->assertSame(['all' => 1, 'unread' => 1, 'messages' => 4, 'waiting' => 1, 'open' => 1, 'closed' => 0], $counts['accounts'][$this->b->id]);
        $this->assertSame(['all' => 5, 'unread' => 3, 'messages' => 9, 'waiting' => 2, 'open' => 4, 'closed' => 1], $counts['total']);
        $this->assertSame(ChatCounts::EMPTY, ChatCounts::forAccounts([])['total']);
    }

    public function test_summary_endpoint_only_counts_numbers_the_user_can_open(): void
    {
        $this->actingAs($this->admin())->getJson('/chat/unread-summary')->assertOk()
            ->assertJsonPath('total.unread', 3)->assertJsonPath('total.messages', 9)
            ->assertJsonPath("accounts.{$this->b->id}.waiting", 1);

        $agent = User::factory()->agent()->create();
        $agent->whatsappAccounts()->attach($this->b);
        $this->actingAs($agent)->getJson('/chat/unread-summary')->assertOk()
            ->assertJsonPath('total.unread', 1)->assertJsonPath('total.messages', 4)
            ->assertJsonMissingPath("accounts.{$this->a->id}");

        auth()->logout();
        $this->getJson('/chat/unread-summary')->assertUnauthorized();
    }

    public function test_conversations_endpoint_returns_tab_counts_and_waiting_filter(): void
    {
        $admin = $this->admin();

        $res = $this->actingAs($admin)->getJson("/chat/conversations?account_id={$this->a->id}")->assertOk();
        $res->assertJsonPath('counts.unread', 2)->assertJsonPath('counts.waiting', 1)->assertJsonPath('counts.closed', 1)
            ->assertJsonPath('unread_total', 2)->assertJsonPath('summary.total.unread', 3);

        $waiting = $this->actingAs($admin)->getJson("/chat/conversations?account_id={$this->a->id}&filter=waiting")->assertOk()->json('data');
        $this->assertCount(1, $waiting);
        $this->assertSame(3, $waiting[0]['unread_count']);

        // ?filter= on the chat page preselects the tab (links from the sidebar counters).
        $this->actingAs($admin)->get('/chat?filter=waiting')->assertOk()->assertSee(str_replace('"', '\u0022', '"filter":"waiting"'), false); // @js() escapes quotes
        $this->actingAs($admin)->get('/chat?filter=evil')->assertOk()->assertSee(str_replace('"', '\u0022', '"filter":"all"'), false);
    }

    public function test_sidebar_shows_unread_badge_and_counters(): void
    {
        $html = $this->actingAs($this->admin())->get('/dashboard')->assertOk()
            ->assertSee('data-chat-counts', false)
            ->assertSee('3 unread chat(s)')
            ->assertSee('Waiting reply')->assertSee('Unread msgs')
            ->getContent();

        $this->assertMatchesRegularExpression('/data-count="messages">9</', $html);
        $this->assertMatchesRegularExpression('/data-count="waiting">2</', $html);
        $this->assertMatchesRegularExpression('/data-count="all">5</', $html);
        $this->assertStringContainsString(route('chat.index', ['filter' => 'waiting']), $html);
    }

    public function test_realtime_check_reports_when_live_updates_are_off(): void
    {
        config(['broadcasting.default' => 'log']);
        $this->assertSame(1, Artisan::call('whatsapp:realtime-check'));
        $this->assertStringContainsString('live updates are off', Artisan::output());

        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => '']);
        $this->assertSame(1, Artisan::call('whatsapp:realtime-check'));
        $this->assertStringContainsString('REVERB_APP_KEY', Artisan::output());
    }
}
