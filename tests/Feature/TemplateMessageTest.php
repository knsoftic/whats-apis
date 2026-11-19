<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Models\WhatsAppAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesMeta;
use Tests\TestCase;

class TemplateMessageTest extends TestCase
{
    use FakesMeta, RefreshDatabase;

    private WhatsAppAccount $account;

    private Conversation $conversation;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = WhatsAppAccount::factory()->connected()->create();
        $contact = Contact::factory()->create(['whatsapp_account_id' => $this->account->id, 'wa_id' => '919811112222']);
        // Window CLOSED — templates must still work.
        $this->conversation = Conversation::factory()->windowExpired()->create(['contact_id' => $contact->id]);
        $this->agent = User::factory()->agent()->create();
        $this->agent->whatsappAccounts()->attach($this->account);
    }

    private function template(array $overrides = []): MessageTemplate
    {
        return MessageTemplate::create(array_merge([
            'whatsapp_account_id' => $this->account->id,
            'meta_template_id' => '555',
            'name' => 'order_update',
            'language' => 'en_US',
            'category' => 'UTILITY',
            'status' => 'APPROVED',
            'parameter_format' => 'POSITIONAL',
            'components' => [
                ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Order {{1}}'],
                ['type' => 'BODY', 'text' => 'Hi {{1}}, your order {{2}} has shipped.'],
                ['type' => 'FOOTER', 'text' => 'KN Softic'],
                ['type' => 'BUTTONS', 'buttons' => [
                    ['type' => 'URL', 'text' => 'Track', 'url' => 'https://example.com/track/{{1}}'],
                    ['type' => 'QUICK_REPLY', 'text' => 'Help'],
                ]],
            ],
        ], $overrides));
    }

    private function url(): string
    {
        return "/chat/conversations/{$this->conversation->id}/template";
    }

    public function test_approved_template_is_sent_with_validated_components_even_outside_window(): void
    {
        Http::fake([$this->graph($this->account->phone_number_id.'/messages*') => $this->metaSendOk('wamid.TPL')]);
        $template = $this->template();

        $this->actingAs($this->agent)->postJson($this->url(), [
            'template_id' => $template->id,
            'variables' => [
                'header' => ['1' => '#1234'],
                'body' => ['2' => 'ORD-1234', '1' => 'Ravi'],
                'buttons' => [0 => 'ORD-1234'],
            ],
        ])->assertCreated()->assertJsonPath('message.type', 'template')->assertJsonPath('message.status', 'sent');

        Http::assertSent(function (Request $r) {
            $t = $r['template'] ?? null;

            return $r['type'] === 'template'
                && $t['name'] === 'order_update'
                && $t['language']['code'] === 'en_US'
                && $t['components'][0] === ['type' => 'header', 'parameters' => [['type' => 'text', 'text' => '#1234']]]
                && $t['components'][1] === ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Ravi'], ['type' => 'text', 'text' => 'ORD-1234']]]
                && $t['components'][2] === ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => 'ORD-1234']]];
        });

        $message = Message::firstOrFail();
        $this->assertSame("Order #1234\nHi Ravi, your order ORD-1234 has shipped.\nKN Softic", $message->body);
    }

    public function test_missing_variables_are_rejected_before_calling_meta(): void
    {
        Http::fake();
        $template = $this->template();

        $this->actingAs($this->agent)->postJson($this->url(), [
            'template_id' => $template->id,
            'variables' => ['body' => ['1' => 'Ravi']],
        ])->assertStatus(422)->assertJsonValidationErrors(['header.1', 'body.2', 'buttons.0']);

        Http::assertNothingSent();
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_variables_with_new_lines_are_rejected(): void
    {
        Http::fake();
        $template = $this->template(['components' => [['type' => 'BODY', 'text' => 'Hi {{1}}']]]);

        $this->actingAs($this->agent)->postJson($this->url(), [
            'template_id' => $template->id,
            'variables' => ['body' => ['1' => "line1\nline2"]],
        ])->assertStatus(422)->assertJsonValidationErrors('body.1');
    }

    public function test_unapproved_template_cannot_be_sent(): void
    {
        Http::fake();
        $template = $this->template(['status' => 'PENDING', 'components' => [['type' => 'BODY', 'text' => 'Hello']]]);

        $this->actingAs($this->agent)->postJson($this->url(), ['template_id' => $template->id])
            ->assertStatus(422)->assertJsonPath('reason', 'template_not_approved');
        Http::assertNothingSent();
    }

    public function test_opted_out_contact_cannot_receive_business_initiated_template(): void
    {
        Http::fake();
        $this->conversation->contact->update(['opt_in_status' => Contact::OPT_OUT]);
        $template = $this->template(['components' => [['type' => 'BODY', 'text' => 'Promo']]]);

        $this->actingAs($this->agent)->postJson($this->url(), ['template_id' => $template->id])
            ->assertStatus(422)->assertJsonPath('reason', 'opted_out');
    }

    public function test_named_parameters_include_parameter_name(): void
    {
        Http::fake([$this->graph($this->account->phone_number_id.'/messages*') => $this->metaSendOk()]);
        $template = $this->template([
            'parameter_format' => 'NAMED',
            'components' => [['type' => 'BODY', 'text' => 'Hi {{customer_name}}, code {{code}}']],
        ]);

        $this->actingAs($this->agent)->postJson($this->url(), [
            'template_id' => $template->id,
            'variables' => ['body' => ['customer_name' => 'Asha', 'code' => '9911']],
        ])->assertCreated();

        Http::assertSent(fn (Request $r) => $r['template']['components'][0]['parameters'] === [
            ['type' => 'text', 'text' => 'Asha', 'parameter_name' => 'customer_name'],
            ['type' => 'text', 'text' => '9911', 'parameter_name' => 'code'],
        ]);
    }

    public function test_document_header_requires_https_link(): void
    {
        Http::fake();
        $template = $this->template(['components' => [
            ['type' => 'HEADER', 'format' => 'DOCUMENT'],
            ['type' => 'BODY', 'text' => 'Your invoice'],
        ]]);

        $this->actingAs($this->agent)->postJson($this->url(), [
            'template_id' => $template->id,
            'variables' => ['header' => ['link' => 'http://insecure.example.com/a.pdf']],
        ])->assertStatus(422)->assertJsonValidationErrors('header.link');
    }

    public function test_template_from_another_account_is_not_found(): void
    {
        Http::fake();
        $other = WhatsAppAccount::factory()->create();
        $foreign = MessageTemplate::create([
            'whatsapp_account_id' => $other->id, 'name' => 'x', 'language' => 'en', 'status' => 'APPROVED',
            'components' => [['type' => 'BODY', 'text' => 'x']],
        ]);

        $this->actingAs($this->agent)->postJson($this->url(), ['template_id' => $foreign->id])->assertNotFound();
    }

    public function test_new_conversation_can_be_started_with_a_template(): void
    {
        Http::fake([$this->graph($this->account->phone_number_id.'/messages*') => $this->metaSendOk('wamid.NEW')]);
        $template = $this->template(['components' => [['type' => 'BODY', 'text' => 'Welcome to KN Softic']]]);

        $this->actingAs($this->agent)->postJson('/chat/start', [
            'account_id' => $this->account->id,
            'phone' => '+91 90000 11111',
            'name' => 'New Lead',
            'template_id' => $template->id,
        ])->assertCreated()->assertJsonPath('conversation.contact.name', 'New Lead');

        $this->assertDatabaseHas('contacts', ['whatsapp_account_id' => $this->account->id, 'wa_id' => '919000011111']);
        Http::assertSent(fn (Request $r) => $r['to'] === '919000011111');
    }

    public function test_agent_cannot_start_conversation_on_unassigned_account(): void
    {
        Http::fake();
        $other = WhatsAppAccount::factory()->create();

        $this->actingAs($this->agent)->postJson('/chat/start', [
            'account_id' => $other->id, 'phone' => '+919000011111', 'template_id' => 1,
        ])->assertNotFound();
    }
}
