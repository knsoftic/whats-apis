<?php

namespace Tests\Feature\Webhook;

use App\Events\MessageReceived;
use App\Events\MessageStatusUpdated;
use App\Jobs\DownloadMessageMedia;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageTemplate;
use App\Models\WebhookEvent;
use App\Models\WhatsAppAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SendsWebhooks;
use Tests\TestCase;

class IncomingMessageProcessingTest extends TestCase
{
    use RefreshDatabase, SendsWebhooks;

    public function test_incoming_text_creates_contact_conversation_and_message(): void
    {
        Event::fake([MessageReceived::class]);
        $account = WhatsAppAccount::factory()->create();

        $this->postWebhook($account, $this->textPayload($account, 'Hello there', 'wamid.TEXT1'))->assertOk();

        $contact = Contact::firstOrFail();
        $this->assertSame('919876543210', $contact->wa_id);
        $this->assertSame('+919876543210', $contact->phone);
        $this->assertSame('Ravi Kumar', $contact->profile_name);
        $this->assertSame($account->id, $contact->whatsapp_account_id);

        $conversation = Conversation::firstOrFail();
        $this->assertSame(1, $conversation->unread_count);
        $this->assertSame('Hello there', $conversation->last_message_preview);
        $this->assertTrue($conversation->isWithinServiceWindow());

        $message = Message::firstOrFail();
        $this->assertSame('wamid.TEXT1', $message->wa_message_id);
        $this->assertSame(Message::IN, $message->direction);
        $this->assertSame('text', $message->type);
        $this->assertSame('Hello there', $message->body);
        $this->assertSame(Message::STATUS_RECEIVED, $message->status);

        $this->assertSame(WebhookEvent::PROCESSED, WebhookEvent::first()->status);
        Event::assertDispatched(MessageReceived::class, fn ($e) => $e->message->is($message));
    }

    public function test_unread_counter_increases_per_message(): void
    {
        $account = WhatsAppAccount::factory()->create();

        $this->postWebhook($account, $this->textPayload($account, 'one', 'wamid.A'));
        $this->postWebhook($account, $this->textPayload($account, 'two', 'wamid.B'));
        $this->postWebhook($account, $this->textPayload($account, 'three', 'wamid.C'));

        $this->assertSame(3, Conversation::firstOrFail()->unread_count);
        $this->assertSame('three', Conversation::firstOrFail()->last_message_preview);
    }

    public function test_image_message_creates_attachment_and_queues_secure_download(): void
    {
        Queue::fake([DownloadMessageMedia::class]);
        $account = WhatsAppAccount::factory()->create();

        $this->postWebhook($account, $this->incomingPayload($account, [
            'type' => 'image',
            'image' => ['id' => 'MEDIA123', 'mime_type' => 'image/jpeg', 'sha256' => str_repeat('a', 64), 'caption' => 'My invoice'],
        ]))->assertOk();

        $message = Message::firstOrFail();
        $this->assertSame('image', $message->type);
        $this->assertSame('My invoice', $message->body);

        $attachment = MessageAttachment::firstOrFail();
        $this->assertSame('MEDIA123', $attachment->wa_media_id);
        $this->assertSame(MessageAttachment::PENDING, $attachment->download_status);
        Queue::assertPushed(DownloadMessageMedia::class, fn ($job) => $job->attachmentId === $attachment->id);
    }

    public function test_every_supported_type_is_stored(): void
    {
        Queue::fake([DownloadMessageMedia::class]);
        $account = WhatsAppAccount::factory()->create();

        $cases = [
            'video' => ['type' => 'video', 'video' => ['id' => 'V1', 'mime_type' => 'video/mp4']],
            'document' => ['type' => 'document', 'document' => ['id' => 'D1', 'mime_type' => 'application/pdf', 'filename' => '../../etc/passwd.pdf']],
            'audio' => ['type' => 'audio', 'audio' => ['id' => 'A1', 'mime_type' => 'audio/ogg; codecs=opus', 'voice' => true]],
            'sticker' => ['type' => 'sticker', 'sticker' => ['id' => 'S1', 'mime_type' => 'image/webp', 'animated' => false]],
            'location' => ['type' => 'location', 'location' => ['latitude' => 28.61, 'longitude' => 77.20, 'name' => 'India Gate', 'address' => 'New Delhi']],
            'contacts' => ['type' => 'contacts', 'contacts' => [['name' => ['formatted_name' => 'Amit Shah'], 'phones' => [['phone' => '+91 99999 00000']]]]],
            'interactive' => ['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'yes', 'title' => 'Yes please']]],
            'button' => ['type' => 'button', 'button' => ['text' => 'Track order', 'payload' => 'TRACK']],
            'unsupported' => ['type' => 'unknown', 'errors' => [['code' => 131051, 'title' => 'Message type unknown']]],
        ];

        foreach ($cases as $expected => $msg) {
            $this->postWebhook($account, $this->incomingPayload($account, $msg))->assertOk();
            $this->assertDatabaseHas('messages', ['type' => $expected]);
        }

        $this->assertSame('India Gate New Delhi', Message::where('type', 'location')->value('body'));
        $this->assertSame('Yes please', Message::where('type', 'interactive')->value('body'));
        $this->assertTrue(Message::where('type', 'audio')->first()->content['voice']);
        // Path traversal in incoming filenames is neutralised.
        $this->assertSame('passwd.pdf', MessageAttachment::where('type', 'document')->value('file_name'));
        $this->assertSame(4, MessageAttachment::count());
    }

    public function test_reaction_does_not_count_as_unread(): void
    {
        $account = WhatsAppAccount::factory()->create();
        $this->postWebhook($account, $this->textPayload($account, 'hello', 'wamid.R0'));
        $this->postWebhook($account, $this->incomingPayload($account, ['type' => 'reaction', 'reaction' => ['message_id' => 'wamid.R0', 'emoji' => '👍']]));

        $this->assertSame(1, Conversation::firstOrFail()->unread_count);
        $this->assertSame('hello', Conversation::firstOrFail()->last_message_preview);
    }

    public function test_reply_context_links_to_original_message(): void
    {
        $account = WhatsAppAccount::factory()->create();
        $this->postWebhook($account, $this->textPayload($account, 'original', 'wamid.ORIG'));
        $this->postWebhook($account, $this->incomingPayload($account, ['type' => 'text', 'text' => ['body' => 'reply'], 'context' => ['id' => 'wamid.ORIG']]));

        $reply = Message::where('body', 'reply')->firstOrFail();
        $this->assertSame(Message::where('wa_message_id', 'wamid.ORIG')->value('id'), $reply->reply_to_message_id);
    }

    public function test_status_updates_move_forward_only(): void
    {
        Event::fake([MessageStatusUpdated::class]);
        $account = WhatsAppAccount::factory()->create();
        $message = $this->outboundMessage($account, 'wamid.OUT1');

        $this->postWebhook($account, $this->statusPayload($account, 'wamid.OUT1', 'sent'));
        $this->postWebhook($account, $this->statusPayload($account, 'wamid.OUT1', 'read'));
        $this->postWebhook($account, $this->statusPayload($account, 'wamid.OUT1', 'delivered')); // late arrival

        $message->refresh();
        $this->assertSame(Message::STATUS_READ, $message->status);
        $this->assertNotNull($message->sent_at);
        $this->assertNotNull($message->delivered_at);
        $this->assertNotNull($message->read_at);
        Event::assertDispatched(MessageStatusUpdated::class);
    }

    public function test_failed_status_stores_meta_error(): void
    {
        $account = WhatsAppAccount::factory()->create();
        $message = $this->outboundMessage($account, 'wamid.FAIL1');

        $this->postWebhook($account, $this->statusPayload($account, 'wamid.FAIL1', 'failed', [
            'errors' => [['code' => 131047, 'title' => 'Re-engagement message', 'error_data' => ['details' => 'Message failed to send because more than 24 hours have passed']]],
        ]));

        $message->refresh();
        $this->assertSame(Message::STATUS_FAILED, $message->status);
        $this->assertSame('131047', $message->error_code);
        $this->assertStringContainsString('Re-engagement message', $message->error_message);
        $this->assertNotNull($message->failed_at);
    }

    public function test_stop_keyword_opts_the_contact_out(): void
    {
        $account = WhatsAppAccount::factory()->create();

        $this->postWebhook($account, $this->textPayload($account, 'STOP', 'wamid.STOP'));

        $contact = Contact::firstOrFail();
        $this->assertSame(Contact::OPT_OUT, $contact->opt_in_status);
        $this->assertNotNull($contact->opted_out_at);
    }

    public function test_events_for_another_number_of_the_same_app_are_routed_to_that_account(): void
    {
        $secret = 'sharedappsecret0123456789abcdef0';
        $first = WhatsAppAccount::factory()->create(['app_secret' => $secret]);
        $second = WhatsAppAccount::factory()->create(['app_secret' => $secret]);

        // Meta posts to the app's single callback URL (first account) for the second number.
        $payload = $this->textPayload($second, 'for second number', 'wamid.ROUTE');
        $this->postWebhook($first, $payload)->assertOk();

        $message = Message::firstOrFail();
        $this->assertSame($second->id, $message->whatsapp_account_id);
        $this->assertSame(0, $first->contacts()->count());
    }

    public function test_events_for_a_number_of_a_different_app_are_ignored(): void
    {
        $first = WhatsAppAccount::factory()->create();
        $other = WhatsAppAccount::factory()->create(); // different App Secret

        $this->postWebhook($first, $this->textPayload($other, 'cross-app', 'wamid.XAPP'))->assertOk();

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_template_status_webhook_updates_template(): void
    {
        $account = WhatsAppAccount::factory()->create();
        $template = MessageTemplate::create([
            'whatsapp_account_id' => $account->id, 'meta_template_id' => '777', 'name' => 'order_update',
            'language' => 'en_US', 'status' => 'PENDING', 'components' => [],
        ]);

        $this->postWebhook($account, $this->webhookEnvelope($account, [
            'event' => 'APPROVED', 'message_template_id' => 777, 'message_template_name' => 'order_update',
            'message_template_language' => 'en_US', 'reason' => 'NONE',
        ], 'message_template_status_update'))->assertOk();

        $this->assertSame('APPROVED', $template->fresh()->status);
    }

    public function test_media_download_job_stores_file_privately(): void
    {
        Storage::fake('local');
        $account = WhatsAppAccount::factory()->create();
        $bytes = 'fake-jpeg-bytes';
        Http::fake([
            'https://graph.facebook.com/v26.0/MEDIA9*' => Http::response([
                'url' => 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=MEDIA9', 'mime_type' => 'image/jpeg',
                'sha256' => hash('sha256', $bytes), 'file_size' => strlen($bytes), 'id' => 'MEDIA9',
            ]),
            'https://lookaside.fbsbx.com/*' => Http::response($bytes),
        ]);

        $this->postWebhook($account, $this->incomingPayload($account, ['type' => 'image', 'image' => ['id' => 'MEDIA9', 'mime_type' => 'image/jpeg']]));

        $attachment = MessageAttachment::firstOrFail();
        $this->assertSame(MessageAttachment::DOWNLOADED, $attachment->download_status);
        $this->assertStringStartsWith("whatsapp-media/{$account->id}/", $attachment->path);
        $this->assertStringEndsWith('.jpg', $attachment->path);
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertSame($bytes, Storage::disk('local')->get($attachment->path));

        Http::assertSent(fn ($r) => str_contains($r->url(), 'lookaside') && $r->hasHeader('Authorization', 'Bearer '.$account->access_token));
    }

    private function outboundMessage(WhatsAppAccount $account, string $wamid): Message
    {
        $contact = Contact::factory()->create(['whatsapp_account_id' => $account->id]);
        $conversation = Conversation::factory()->create(['contact_id' => $contact->id]);

        return Message::factory()->outbound()->create([
            'conversation_id' => $conversation->id,
            'wa_message_id' => $wamid,
            'status' => Message::STATUS_PENDING,
            'sent_at' => null,
        ]);
    }
}
