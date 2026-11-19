<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Models\WhatsAppAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every page renders without errors for the roles that may open it.
 */
class PageSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_pages_render_for_super_admin(): void
    {
        $admin = $this->superAdmin();
        $account = WhatsAppAccount::factory()->connected()->create();
        $contact = Contact::factory()->create(['whatsapp_account_id' => $account->id]);
        $conversation = Conversation::factory()->withinWindow()->create(['contact_id' => $contact->id]);
        Message::factory()->create(['conversation_id' => $conversation->id]);
        Message::factory()->failed()->create(['conversation_id' => $conversation->id]);
        $contact->notes()->create(['user_id' => $admin->id, 'body' => 'note']);
        $template = MessageTemplate::create([
            'whatsapp_account_id' => $account->id, 'name' => 'doc_tpl', 'language' => 'en', 'status' => 'APPROVED',
            'components' => [
                ['type' => 'HEADER', 'format' => 'DOCUMENT'],
                ['type' => 'BODY', 'text' => 'Invoice {{1}}'],
                ['type' => 'FOOTER', 'text' => 'Thanks'],
                ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => 'Pay', 'url' => 'https://x.test/{{1}}']]],
            ],
        ]);
        $user = User::factory()->agent()->create();

        $pages = [
            '/dashboard', '/chat', "/chat?conversation={$conversation->id}", "/chat?account={$account->id}",
            '/contacts', '/contacts/create', "/contacts/{$contact->id}", "/contacts/{$contact->id}/edit", '/contacts/import',
            '/templates', "/templates/{$template->id}",
            '/whatsapp-accounts', '/whatsapp-accounts/create', "/whatsapp-accounts/{$account->id}", "/whatsapp-accounts/{$account->id}/edit",
            '/users', '/users/create', "/users/{$user->id}/edit",
            '/activity-logs', '/profile',
        ];

        foreach ($pages as $page) {
            $this->actingAs($admin)->get($page)->assertOk();
        }
    }

    public function test_agent_sees_only_allowed_navigation(): void
    {
        $agent = $this->agent();

        $this->actingAs($agent)->get('/dashboard')
            ->assertOk()
            ->assertSee('Chats')
            ->assertSee('Contacts')
            ->assertDontSee('WhatsApp Accounts')
            ->assertDontSee('Activity Logs');

        $this->actingAs($agent)->get('/chat')->assertOk()->assertSee('No WhatsApp number available');
    }

    public function test_guest_pages_render(): void
    {
        $this->get('/login')->assertOk()->assertSee('KN Softic');
        $this->get('/forgot-password')->assertOk();
        $this->get('/')->assertRedirect('/dashboard');
    }
}
