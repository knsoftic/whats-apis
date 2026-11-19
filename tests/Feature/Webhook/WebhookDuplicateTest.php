<?php

namespace Tests\Feature\Webhook;

use App\Jobs\ProcessWebhookEvent;
use App\Models\Message;
use App\Models\WhatsAppAccount;
use App\Services\WhatsApp\WebhookProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\SendsWebhooks;
use Tests\TestCase;

class WebhookDuplicateTest extends TestCase
{
    use RefreshDatabase, SendsWebhooks;

    public function test_identical_payload_is_stored_and_queued_only_once(): void
    {
        Queue::fake();
        $account = WhatsAppAccount::factory()->create();
        $payload = $this->textPayload($account, 'hi', 'wamid.DUP1');

        $this->postWebhook($account, $payload)->assertOk()->assertSeeText('EVENT_RECEIVED');
        $this->postWebhook($account, $payload)->assertOk()->assertSeeText('DUPLICATE');

        $this->assertDatabaseCount('webhook_events', 1);
        Queue::assertPushed(ProcessWebhookEvent::class, 1);
    }

    public function test_same_message_in_a_different_payload_creates_only_one_message(): void
    {
        $account = WhatsAppAccount::factory()->create();

        $first = $this->textPayload($account, 'hi', 'wamid.SAME');
        $second = $first;
        $second['entry'][0]['changes'][0]['value']['metadata']['display_phone_number'] = 'changed-so-hash-differs';

        $this->postWebhook($account, $first)->assertOk();
        $this->postWebhook($account, $second)->assertOk();

        $this->assertDatabaseCount('webhook_events', 2);
        $this->assertSame(1, Message::where('wa_message_id', 'wamid.SAME')->count());
        $this->assertSame(1, $account->conversations()->first()->unread_count);
    }

    public function test_processing_the_same_event_twice_is_idempotent(): void
    {
        $account = WhatsAppAccount::factory()->create();
        $processor = app(WebhookProcessor::class);
        $msg = ['from' => '919876543210', 'id' => 'wamid.IDEMP', 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => 'x']];

        $processor->storeIncomingMessage($account, $msg);
        $processor->storeIncomingMessage($account, $msg);

        $this->assertSame(1, Message::count());
    }

    public function test_duplicate_status_webhooks_do_not_change_anything(): void
    {
        $account = WhatsAppAccount::factory()->create();
        $message = Message::factory()->outbound()->create([
            'conversation_id' => \App\Models\Conversation::factory()->create([
                'contact_id' => \App\Models\Contact::factory()->create(['whatsapp_account_id' => $account->id])->id,
            ])->id,
            'wa_message_id' => 'wamid.STATUS1',
            'status' => Message::STATUS_SENT,
        ]);

        $ts = now()->timestamp;
        $this->postWebhook($account, $this->statusPayload($account, 'wamid.STATUS1', 'delivered', timestamp: $ts))->assertOk();
        $deliveredAt = $message->fresh()->delivered_at;

        $this->travel(5)->minutes();
        $this->postWebhook($account, $this->statusPayload($account, 'wamid.STATUS1', 'delivered', timestamp: $ts + 300))->assertOk();

        $this->assertEquals($deliveredAt, $message->fresh()->delivered_at);
        $this->assertSame(Message::STATUS_DELIVERED, $message->fresh()->status);
    }
}
