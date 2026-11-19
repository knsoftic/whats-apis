<?php

namespace Tests\Feature;

use App\Events\ConversationRead;
use App\Events\MessageReceived;
use App\Jobs\SendReadReceipt;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Models\WhatsAppAccount;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesMeta;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use FakesMeta, RefreshDatabase;

    private WhatsAppAccount $account;

    private Conversation $conversation;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = WhatsAppAccount::factory()->connected()->create(['name' => 'Assigned Line']);
        $contact = Contact::factory()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Priya Sharma']);
        $this->conversation = Conversation::factory()->withinWindow()->create(['contact_id' => $contact->id, 'unread_count' => 3]);
        $this->agent = User::factory()->agent()->create();
        $this->agent->whatsappAccounts()->attach($this->account);
    }

    public function test_chat_page_lists_only_accessible_numbers(): void
    {
        WhatsAppAccount::factory()->create(['name' => 'Secret Other Line']);

        $this->actingAs($this->agent)->get('/chat')
            ->assertOk()
            ->assertSee('Assigned Line')
            ->assertDontSee('Secret Other Line');
    }

    public function test_conversation_list_returns_account_conversations(): void
    {
        $this->actingAs($this->agent)->getJson("/chat/conversations?account_id={$this->account->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.contact.name', 'Priya Sharma')
            ->assertJsonPath('data.0.unread_count', 3)
            ->assertJsonPath('data.0.within_window', true)
            ->assertJsonPath('unread_total', 1);
    }

    public function test_conversation_search_and_unread_filter(): void
    {
        $other = Conversation::factory()->create([
            'contact_id' => Contact::factory()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Rahul'])->id,
            'unread_count' => 0,
        ]);

        $this->actingAs($this->agent)->getJson("/chat/conversations?account_id={$this->account->id}&q=priya")
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->conversation->id);

        $this->actingAs($this->agent)->getJson("/chat/conversations?account_id={$this->account->id}&filter=unread")
            ->assertJsonCount(1, 'data')->assertJsonMissing(['id' => $other->id]);
    }

    public function test_opening_a_conversation_returns_messages_in_order_with_pagination(): void
    {
        Message::factory()->count(45)->create(['conversation_id' => $this->conversation->id]);

        $first = $this->actingAs($this->agent)->getJson("/chat/conversations/{$this->conversation->id}")
            ->assertOk()->assertJsonCount(40, 'messages')->assertJsonPath('has_more', true)->json();

        $ids = array_column($first['messages'], 'id');
        $this->assertSame($ids, collect($ids)->sort()->values()->all());

        $this->actingAs($this->agent)->getJson("/chat/conversations/{$this->conversation->id}?before_id={$ids[0]}")
            ->assertJsonCount(5, 'messages')->assertJsonPath('has_more', false);

        $last = end($ids);
        $this->actingAs($this->agent)->getJson("/chat/conversations/{$this->conversation->id}?after_id={$last}")
            ->assertJsonCount(0, 'messages');
    }

    public function test_marking_read_resets_unread_and_sends_read_receipt_to_meta(): void
    {
        Event::fake([ConversationRead::class]);
        Http::fake([$this->graph($this->account->phone_number_id.'/messages*') => Http::response(['success' => true])]);
        $incoming = Message::factory()->create(['conversation_id' => $this->conversation->id, 'wa_message_id' => 'wamid.README']);

        $this->actingAs($this->agent)->postJson("/chat/conversations/{$this->conversation->id}/read")->assertOk();

        $this->assertSame(0, $this->conversation->fresh()->unread_count);
        Event::assertDispatched(ConversationRead::class);
        Http::assertSent(fn (Request $r) => $r['status'] === 'read' && $r['message_id'] === 'wamid.README');
        $this->assertNotNull($incoming->fresh()->read_at);
    }

    public function test_read_receipt_is_queued(): void
    {
        Queue::fake();
        Message::factory()->create(['conversation_id' => $this->conversation->id]);

        $this->actingAs($this->agent)->postJson("/chat/conversations/{$this->conversation->id}/read")->assertOk();

        Queue::assertPushed(SendReadReceipt::class);
    }

    public function test_conversation_can_be_closed_and_reopened(): void
    {
        $this->actingAs($this->agent)->patchJson("/chat/conversations/{$this->conversation->id}", ['status' => 'closed'])
            ->assertOk()->assertJsonPath('conversation.status', 'closed');
        $this->actingAs($this->agent)->patchJson("/chat/conversations/{$this->conversation->id}", ['status' => 'open'])
            ->assertJsonPath('conversation.status', 'open');
    }

    public function test_message_search_finds_text_within_the_account_only(): void
    {
        Message::factory()->create(['conversation_id' => $this->conversation->id, 'body' => 'Where is my invoice 7781?']);
        $foreign = WhatsAppAccount::factory()->create();
        Message::factory()->create([
            'conversation_id' => Conversation::factory()->create(['contact_id' => Contact::factory()->create(['whatsapp_account_id' => $foreign->id])->id])->id,
            'body' => 'invoice 7781 from another account',
        ]);

        $this->actingAs($this->agent)->getJson("/chat/search?account_id={$this->account->id}&q=invoice 7781")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.conversation_id', $this->conversation->id);
    }

    public function test_templates_endpoint_lists_only_approved_templates(): void
    {
        $this->account->templates()->create(['name' => 'hello_world', 'language' => 'en_US', 'status' => 'APPROVED', 'components' => [['type' => 'BODY', 'text' => 'Hi {{1}}']]]);
        $this->account->templates()->create(['name' => 'draft_one', 'language' => 'en_US', 'status' => 'PENDING', 'components' => []]);

        $this->actingAs($this->agent)->getJson("/chat/templates?account_id={$this->account->id}")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'hello_world')
            ->assertJsonPath('data.0.body_placeholders', ['1']);
    }

    public function test_media_is_served_only_to_authorised_users(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('whatsapp-media/1/x.jpg', 'jpeg-bytes');
        $message = Message::factory()->create(['conversation_id' => $this->conversation->id, 'type' => 'image']);
        $attachment = MessageAttachment::create([
            'message_id' => $message->id, 'type' => 'image', 'mime_type' => 'image/jpeg', 'disk' => 'local',
            'path' => 'whatsapp-media/1/x.jpg', 'download_status' => MessageAttachment::DOWNLOADED, 'size' => 10,
        ]);

        $this->actingAs($this->agent)->get("/media/{$attachment->id}")
            ->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff');

        $outsider = User::factory()->agent()->create();
        $this->actingAs($outsider)->get("/media/{$attachment->id}")->assertNotFound();
        $this->post('/logout');
        $this->get("/media/{$attachment->id}")->assertRedirect('/login');
    }

    public function test_customer_documents_are_always_downloaded_never_rendered(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('whatsapp-media/1/evil.html', '<script>alert(1)</script>');
        $message = Message::factory()->create(['conversation_id' => $this->conversation->id, 'type' => 'document']);
        $attachment = MessageAttachment::create([
            'message_id' => $message->id, 'type' => 'document', 'mime_type' => 'text/html', 'disk' => 'local',
            'path' => 'whatsapp-media/1/evil.html', 'file_name' => 'evil.html', 'download_status' => MessageAttachment::DOWNLOADED,
        ]);

        $response = $this->actingAs($this->agent)->get("/media/{$attachment->id}")->assertOk();

        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
    }

    public function test_broadcast_events_use_the_private_account_channel(): void
    {
        $message = Message::factory()->create(['conversation_id' => $this->conversation->id]);
        $channels = (new MessageReceived($message))->broadcastOn();

        $this->assertSame('private-whatsapp.account.'.$this->account->id, $channels[0]->name);
        $payload = (new MessageReceived($message))->broadcastWith();
        $this->assertSame($message->id, $payload['message']['id']);
        $this->assertArrayNotHasKey('api_response', $payload['message']);
    }

    public function test_private_channel_authorisation_follows_account_access(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '1',
        ]);
        app(BroadcastManager::class)->forgetDrivers();
        require base_path('routes/channels.php');

        $channel = 'private-whatsapp.account.'.$this->account->id;

        $this->actingAs($this->agent)->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel])
            ->assertOk()->assertJsonStructure(['auth']);

        $outsider = User::factory()->agent()->create();
        $this->actingAs($outsider)->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel])
            ->assertForbidden();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel])
            ->assertOk();
    }
}
