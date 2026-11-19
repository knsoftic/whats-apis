<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Models\WhatsAppAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * An Agent assigned to account A must never see or touch anything of account B.
 */
class AccountIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private WhatsAppAccount $mine;

    private WhatsAppAccount $theirs;

    private Conversation $theirConversation;

    private Contact $theirContact;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();

        $this->mine = WhatsAppAccount::factory()->connected()->create(['name' => 'Mine Line']);
        $this->theirs = WhatsAppAccount::factory()->connected()->create(['name' => 'Their Line']);

        $this->agent = User::factory()->agent()->create();
        $this->agent->whatsappAccounts()->attach($this->mine);

        $this->theirContact = Contact::factory()->create(['whatsapp_account_id' => $this->theirs->id, 'name' => 'Their Customer']);
        $this->theirConversation = Conversation::factory()->withinWindow()->create(['contact_id' => $this->theirContact->id, 'unread_count' => 7]);
        Message::factory()->count(3)->create(['conversation_id' => $this->theirConversation->id, 'body' => 'their secret text']);
    }

    public function test_chat_endpoints_are_isolated(): void
    {
        $c = $this->theirConversation->id;

        $this->actingAs($this->agent)->getJson("/chat/conversations?account_id={$this->theirs->id}")->assertNotFound();
        $this->actingAs($this->agent)->getJson("/chat/conversations/{$c}")->assertForbidden();
        $this->actingAs($this->agent)->postJson("/chat/conversations/{$c}/read")->assertForbidden();
        $this->actingAs($this->agent)->patchJson("/chat/conversations/{$c}", ['status' => 'closed'])->assertForbidden();
        $this->actingAs($this->agent)->postJson("/chat/conversations/{$c}/messages", ['body' => 'x'])->assertForbidden();
        $this->actingAs($this->agent)->postJson("/chat/conversations/{$c}/template", ['template_id' => 1])->assertForbidden();
        $this->actingAs($this->agent)->getJson("/chat/search?account_id={$this->theirs->id}&q=secret")->assertNotFound();
        $this->actingAs($this->agent)->getJson("/chat/templates?account_id={$this->theirs->id}")->assertNotFound();

        $failed = Message::factory()->failed()->create(['conversation_id' => $c]);
        $this->actingAs($this->agent)->postJson("/chat/messages/{$failed->id}/retry")->assertForbidden();

        $this->assertSame(7, $this->theirConversation->fresh()->unread_count);
        Http::assertNothingSent();
    }

    public function test_opening_chat_page_with_foreign_conversation_id_shows_nothing_of_it(): void
    {
        $this->actingAs($this->agent)->get("/chat?conversation={$this->theirConversation->id}")
            ->assertOk()
            ->assertDontSee('Their Line')
            ->assertDontSee('Their Customer');
    }

    public function test_contacts_are_isolated(): void
    {
        Contact::factory()->create(['whatsapp_account_id' => $this->mine->id, 'name' => 'My Customer']);

        $this->actingAs($this->agent)->get('/contacts')->assertOk()->assertSee('My Customer')->assertDontSee('Their Customer');
        $this->actingAs($this->agent)->get("/contacts?account_id={$this->theirs->id}")->assertNotFound();
        $this->actingAs($this->agent)->get("/contacts/{$this->theirContact->id}")->assertForbidden();
        $this->actingAs($this->agent)->get("/contacts/{$this->theirContact->id}/edit")->assertForbidden();
        $this->actingAs($this->agent)->put("/contacts/{$this->theirContact->id}", ['phone' => '+919999999999', 'opt_in_status' => 'unknown'])->assertForbidden();
        $this->actingAs($this->agent)->delete("/contacts/{$this->theirContact->id}")->assertForbidden();
        $this->actingAs($this->agent)->post("/contacts/{$this->theirContact->id}/notes", ['body' => 'x'])->assertForbidden();

        $this->assertModelExists($this->theirContact);
    }

    public function test_templates_are_isolated(): void
    {
        $template = MessageTemplate::create(['whatsapp_account_id' => $this->theirs->id, 'name' => 'their_template', 'language' => 'en', 'status' => 'APPROVED', 'components' => [['type' => 'BODY', 'text' => 'Hi']]]);

        $this->actingAs($this->agent)->get("/templates?account_id={$this->theirs->id}")->assertNotFound();
        $this->actingAs($this->agent)->get("/templates/{$template->id}")->assertNotFound();
        $this->actingAs($this->agent)->post("/templates/{$template->id}/send", ['phone' => '+919999999999'])->assertNotFound();
        $this->actingAs($this->agent)->get('/templates')->assertOk()->assertDontSee('their_template');
    }

    public function test_media_of_another_account_is_not_served(): void
    {
        $message = Message::where('conversation_id', $this->theirConversation->id)->firstOrFail();
        $attachment = MessageAttachment::create(['message_id' => $message->id, 'type' => 'image', 'mime_type' => 'image/jpeg', 'path' => 'x.jpg', 'download_status' => 'downloaded']);

        $this->actingAs($this->agent)->get("/media/{$attachment->id}")->assertNotFound();
    }

    public function test_dashboard_counts_only_accessible_accounts(): void
    {
        $response = $this->actingAs($this->agent)->get('/dashboard')->assertOk();

        $response->assertDontSee('Their Line')->assertDontSee('Their Customer');
        $this->assertSame(0, $response->viewData('stats')['received']);
        $this->assertSame(0, $response->viewData('stats')['unread']);
        $this->assertSame(1, $response->viewData('stats')['numbers_total']);
    }

    public function test_cannot_switch_to_an_unassigned_account(): void
    {
        $this->actingAs($this->agent)->post('/current-account', ['account_id' => $this->theirs->id])->assertForbidden();
        $this->actingAs($this->agent)->post('/current-account', ['account_id' => $this->mine->id])->assertRedirect();
    }

    public function test_agent_cannot_start_conversation_or_import_into_foreign_account(): void
    {
        $this->actingAs($this->agent)->postJson('/chat/start', ['account_id' => $this->theirs->id, 'phone' => '+919999999999', 'template_id' => 1])->assertNotFound();
    }

    public function test_admin_with_access_all_sees_both_accounts(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->getJson("/chat/conversations?account_id={$this->theirs->id}")->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($admin)->get('/whatsapp-accounts')->assertSee('Mine Line')->assertSee('Their Line');
    }
}
