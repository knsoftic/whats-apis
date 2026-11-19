<?php

namespace Tests\Feature;

use App\Models\MessageTemplate;
use App\Models\WhatsAppAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesMeta;
use Tests\TestCase;

class TemplateSyncTest extends TestCase
{
    use FakesMeta, RefreshDatabase;

    public function test_templates_are_synced_from_meta_with_pagination_and_removal(): void
    {
        $account = WhatsAppAccount::factory()->connected()->create();
        $stale = MessageTemplate::create(['whatsapp_account_id' => $account->id, 'name' => 'deleted_in_meta', 'language' => 'en', 'status' => 'APPROVED', 'components' => []]);

        Http::fakeSequence($this->graph("{$account->waba_id}/message_templates*"))
            ->push([
                'data' => [[
                    'id' => '111', 'name' => 'order_update', 'language' => 'en_US', 'status' => 'APPROVED', 'category' => 'UTILITY',
                    'parameter_format' => 'POSITIONAL',
                    'components' => [['type' => 'BODY', 'text' => 'Hi {{1}}, order {{2}} shipped.']],
                ]],
                'paging' => ['cursors' => ['after' => 'CURSOR1'], 'next' => 'https://graph.facebook.com/next'],
            ])
            ->push([
                'data' => [[
                    'id' => '222', 'name' => 'promo', 'language' => 'hi', 'status' => 'REJECTED', 'category' => 'MARKETING',
                    'rejected_reason' => 'INVALID_FORMAT', 'components' => [['type' => 'BODY', 'text' => 'Sale!']],
                ]],
                'paging' => ['cursors' => ['after' => 'CURSOR2']],
            ]);

        $this->actingAs($this->admin())->post('/templates/sync', ['account_id' => $account->id])
            ->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseCount('message_templates', 2);
        $this->assertModelMissing($stale);

        $order = MessageTemplate::where('name', 'order_update')->firstOrFail();
        $this->assertTrue($order->isApproved());
        $this->assertSame(['1', '2'], $order->bodyPlaceholders());
        $this->assertSame('111', $order->meta_template_id);
        $this->assertSame('INVALID_FORMAT', MessageTemplate::where('name', 'promo')->value('rejected_reason'));

        Http::assertSent(fn ($r) => str_contains($r->url(), 'after=CURSOR1'));
    }

    public function test_sync_error_is_shown_and_nothing_is_deleted(): void
    {
        $account = WhatsAppAccount::factory()->create();
        MessageTemplate::create(['whatsapp_account_id' => $account->id, 'name' => 'keep_me', 'language' => 'en', 'status' => 'APPROVED', 'components' => []]);
        $this->fakeMetaInvalidToken();

        $this->actingAs($this->admin())->post('/templates/sync', ['account_id' => $account->id])
            ->assertSessionHas('error');

        $this->assertDatabaseHas('message_templates', ['name' => 'keep_me']);
    }

    public function test_agent_cannot_sync_but_can_view_templates_of_assigned_account(): void
    {
        $account = WhatsAppAccount::factory()->create();
        $template = MessageTemplate::create(['whatsapp_account_id' => $account->id, 'name' => 'hello_world', 'language' => 'en_US', 'status' => 'APPROVED', 'components' => [['type' => 'BODY', 'text' => 'Hello {{1}}']]]);
        $agent = $this->agent();
        $agent->whatsappAccounts()->attach($account);

        $this->actingAs($agent)->post('/templates/sync', ['account_id' => $account->id])->assertForbidden();
        $this->actingAs($agent)->get("/templates?account_id={$account->id}")->assertOk()->assertSee('hello_world');
        $this->actingAs($agent)->get("/templates/{$template->id}")->assertOk()->assertSee('Send this template');
    }

    public function test_template_can_be_sent_from_template_page(): void
    {
        $account = WhatsAppAccount::factory()->connected()->create();
        $template = MessageTemplate::create(['whatsapp_account_id' => $account->id, 'name' => 'hello_world', 'language' => 'en_US', 'status' => 'APPROVED', 'components' => [['type' => 'BODY', 'text' => 'Hello {{1}}']]]);
        Http::fake([$this->graph($account->phone_number_id.'/messages*') => $this->metaSendOk('wamid.FROMPAGE')]);

        $this->actingAs($this->admin())->post("/templates/{$template->id}/send", [
            'phone' => '+91 90000 22222', 'variables' => ['body' => ['1' => 'Asha']],
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('messages', ['wa_message_id' => 'wamid.FROMPAGE', 'type' => 'template', 'recipient' => '919000022222']);
    }
}
