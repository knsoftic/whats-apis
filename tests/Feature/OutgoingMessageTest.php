<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsAppAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesMeta;
use Tests\TestCase;

class OutgoingMessageTest extends TestCase
{
    use FakesMeta, RefreshDatabase;

    private WhatsAppAccount $account;

    private Conversation $conversation;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = WhatsAppAccount::factory()->connected()->create(['display_phone_number' => '+1 555-000-1111']);
        $contact = Contact::factory()->create(['whatsapp_account_id' => $this->account->id, 'wa_id' => '919876543210']);
        $this->conversation = Conversation::factory()->withinWindow()->create(['contact_id' => $contact->id]);
        $this->agent = User::factory()->agent()->create();
        $this->agent->whatsappAccounts()->attach($this->account);
    }

    private function messagesUrl(): string
    {
        return $this->graph($this->account->phone_number_id.'/messages*');
    }

    public function test_agent_can_send_a_text_message(): void
    {
        Http::fake([$this->messagesUrl() => $this->metaSendOk('wamid.SENT1')]);

        $response = $this->actingAs($this->agent)
            ->postJson("/chat/conversations/{$this->conversation->id}/messages", ['body' => 'Hello from KN Softic'])
            ->assertCreated()
            ->assertJsonPath('message.status', 'sent')
            ->assertJsonPath('message.wa_message_id', 'wamid.SENT1');

        Http::assertSent(function (Request $r) {
            return $r->method() === 'POST'
                && str_contains($r->url(), "/v26.0/{$this->account->phone_number_id}/messages")
                && $r['messaging_product'] === 'whatsapp'
                && $r['to'] === '919876543210'
                && $r['type'] === 'text'
                && $r['text']['body'] === 'Hello from KN Softic';
        });

        $message = Message::firstOrFail();
        $this->assertSame(Message::OUT, $message->direction);
        $this->assertSame('15550001111', $message->sender);
        $this->assertSame('919876543210', $message->recipient);
        $this->assertSame($this->agent->id, $message->sent_by_user_id);
        $this->assertSame($this->account->id, $message->whatsapp_account_id);
        $this->assertSame('wamid.SENT1', $message->api_response['messages'][0]['id']);
        $this->assertNotNull($message->sent_at);
        $this->assertSame('Hello from KN Softic', $this->conversation->fresh()->last_message_preview);
    }

    public function test_reply_includes_context_message_id(): void
    {
        Http::fake([$this->messagesUrl() => $this->metaSendOk('wamid.REPLY')]);
        $original = Message::factory()->create(['conversation_id' => $this->conversation->id, 'wa_message_id' => 'wamid.CUSTOMER1']);

        $this->actingAs($this->agent)->postJson("/chat/conversations/{$this->conversation->id}/messages", [
            'body' => 'Answer', 'reply_to_id' => $original->id,
        ])->assertCreated();

        Http::assertSent(fn (Request $r) => ($r['context']['message_id'] ?? null) === 'wamid.CUSTOMER1');
        $this->assertSame($original->id, Message::where('body', 'Answer')->value('reply_to_message_id'));
    }

    public function test_free_form_message_is_blocked_outside_24h_window(): void
    {
        Http::fake();
        $this->conversation->update(['last_inbound_at' => now()->subHours(25)]);

        $this->actingAs($this->agent)
            ->postJson("/chat/conversations/{$this->conversation->id}/messages", ['body' => 'Hi'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'window_closed');

        Http::assertNothingSent();
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_never_messaged_contact_requires_template(): void
    {
        Http::fake();
        $this->conversation->update(['last_inbound_at' => null]);

        $this->actingAs($this->agent)
            ->postJson("/chat/conversations/{$this->conversation->id}/messages", ['body' => 'Hi'])
            ->assertStatus(422)->assertJsonPath('reason', 'window_closed');
    }

    public function test_disabled_account_cannot_send(): void
    {
        Http::fake();
        $this->account->update(['is_active' => false]);

        $this->actingAs($this->agent)
            ->postJson("/chat/conversations/{$this->conversation->id}/messages", ['body' => 'Hi'])
            ->assertStatus(422)->assertJsonPath('reason', 'account_disabled');
        Http::assertNothingSent();
    }

    public function test_agent_without_access_to_the_account_gets_403(): void
    {
        Http::fake();
        $outsider = User::factory()->agent()->create();

        $this->actingAs($outsider)
            ->postJson("/chat/conversations/{$this->conversation->id}/messages", ['body' => 'Hi'])
            ->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_permanent_meta_error_marks_message_failed(): void
    {
        Http::fake([$this->messagesUrl() => $this->metaError(131026, 'Message undeliverable')]);

        $this->actingAs($this->agent)
            ->postJson("/chat/conversations/{$this->conversation->id}/messages", ['body' => 'Hi'])
            ->assertCreated()
            ->assertJsonPath('message.status', 'failed');

        $message = Message::firstOrFail();
        $this->assertSame('131026', $message->error_code);
        $this->assertStringContainsString('undeliverable', $message->error_message);
    }

    public function test_expired_token_marks_message_failed_and_account_api_failed(): void
    {
        Http::fake([$this->messagesUrl() => $this->metaError(190, 'Error validating access token', 401)]);

        $this->actingAs($this->agent)->postJson("/chat/conversations/{$this->conversation->id}/messages", ['body' => 'Hi']);

        $this->assertSame(Message::STATUS_FAILED, Message::firstOrFail()->status);
        $this->assertSame(WhatsAppAccount::API_FAILED, $this->account->fresh()->api_status);
    }

    public function test_rate_limited_message_is_kept_pending_for_retry(): void
    {
        Queue::fake();
        Http::fake([$this->messagesUrl() => $this->metaError(130429, 'Rate limit hit', 429)]);

        $this->actingAs($this->agent)->postJson("/chat/conversations/{$this->conversation->id}/messages", ['body' => 'Hi'])->assertCreated();
        Queue::assertPushed(SendWhatsAppMessage::class);

        $message = Message::firstOrFail();
        (new SendWhatsAppMessage($message->id))->handle();

        $message->refresh();
        $this->assertSame(Message::STATUS_PENDING, $message->status);
        $this->assertStringStartsWith('Retrying:', $message->error_message);
    }

    public function test_job_never_sends_the_same_message_twice(): void
    {
        Http::fake([$this->messagesUrl() => $this->metaSendOk('wamid.ONCE')]);
        $this->actingAs($this->agent)->postJson("/chat/conversations/{$this->conversation->id}/messages", ['body' => 'Hi']);

        (new SendWhatsAppMessage(Message::firstOrFail()->id))->handle();

        Http::assertSentCount(1);
    }

    public function test_image_is_uploaded_to_meta_then_sent_by_media_id(): void
    {
        Storage::fake('local');
        Http::fake([
            $this->graph($this->account->phone_number_id.'/media*') => Http::response(['id' => 'MEDIA-UP-1']),
            $this->messagesUrl() => $this->metaSendOk('wamid.IMG'),
        ]);

        $this->actingAs($this->agent)->post("/chat/conversations/{$this->conversation->id}/media", [
            'file' => UploadedFile::fake()->image('photo.jpg', 200, 200),
            'caption' => 'Our catalogue',
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('message.type', 'image');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/media') && $r->isMultipart());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/messages')
            && $r['type'] === 'image' && $r['image']['id'] === 'MEDIA-UP-1' && $r['image']['caption'] === 'Our catalogue');

        $attachment = Message::firstOrFail()->attachments()->firstOrFail();
        $this->assertSame('MEDIA-UP-1', $attachment->wa_media_id);
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_unsupported_file_type_is_rejected(): void
    {
        Storage::fake('local');
        Http::fake();

        $this->actingAs($this->agent)->post("/chat/conversations/{$this->conversation->id}/media", [
            'file' => UploadedFile::fake()->createWithContent('virus.exe', 'MZ fake binary'),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('file');

        Http::assertNothingSent();
    }

    public function test_file_renamed_to_jpg_but_not_an_image_is_rejected(): void
    {
        Storage::fake('local');
        Http::fake();

        $this->actingAs($this->agent)->post("/chat/conversations/{$this->conversation->id}/media", [
            'file' => UploadedFile::fake()->createWithContent('fake.jpg', '<?php echo "hi"; ?>'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_failed_message_can_be_retried(): void
    {
        Http::fakeSequence($this->messagesUrl())
            ->pushResponse($this->metaError(131026, 'Message undeliverable'))
            ->pushResponse($this->metaSendOk('wamid.RETRY'));

        $this->actingAs($this->agent)->postJson("/chat/conversations/{$this->conversation->id}/messages", ['body' => 'Hi']);
        $message = Message::firstOrFail();
        $this->assertSame(Message::STATUS_FAILED, $message->status);

        $this->actingAs($this->agent)->postJson("/chat/messages/{$message->id}/retry")->assertOk()
            ->assertJsonPath('message.status', 'sent');

        $this->assertSame('wamid.RETRY', $message->fresh()->wa_message_id);
    }
}
