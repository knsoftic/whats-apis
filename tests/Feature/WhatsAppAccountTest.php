<?php

namespace Tests\Feature;

use App\Models\WhatsAppAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesMeta;
use Tests\TestCase;

class WhatsAppAccountTest extends TestCase
{
    use FakesMeta, RefreshDatabase;

    public function test_admin_can_add_an_account_and_it_is_validated_with_meta(): void
    {
        $payload = $this->accountPayload();
        $this->fakeMetaValid($payload['waba_id'], $payload['phone_number_id']);

        $response = $this->actingAs($this->admin())->post('/whatsapp-accounts', $payload);

        $account = WhatsAppAccount::firstOrFail();
        $response->assertRedirect(route('accounts.show', $account))->assertSessionHas('success');

        $this->assertSame('Sales India', $account->name);
        $this->assertSame(WhatsAppAccount::API_CONNECTED, $account->api_status);
        $this->assertSame('+91 98765 43210', $account->display_phone_number);
        $this->assertSame('KN Softic', $account->verified_name);
        $this->assertSame(WhatsAppAccount::SUB_SUBSCRIBED, $account->subscription_status);
        // Saving + valid API does NOT mean the webhook works.
        $this->assertSame(WhatsAppAccount::WEBHOOK_NOT_VERIFIED, $account->webhook_status);
        $this->assertFalse($account->isOperational());
        $this->assertNotEmpty($account->uuid);
        $this->assertDatabaseHas('activity_logs', ['action' => 'account.created']);
    }

    public function test_callback_url_is_shown_before_saving_and_kept_after_saving(): void
    {
        $admin = $this->admin();

        // The "Add account" form already shows the Callback URL + a hidden id for it.
        $html = $this->actingAs($admin)->get('/whatsapp-accounts/create')->assertOk()->assertSee('Callback URL')->getContent();
        $this->assertSame(1, preg_match('~name="uuid" value="([0-9a-f-]{36})"~', $html, $m));
        $uuid = $m[1];
        $this->assertStringContainsString(route('webhooks.whatsapp.verify', ['account' => $uuid]), $html);

        // Saving keeps exactly that id, so the URL pasted into Meta keeps working.
        $payload = $this->accountPayload(['uuid' => $uuid]);
        $this->fakeMetaValid($payload['waba_id'], $payload['phone_number_id']);
        $this->actingAs($admin)->post('/whatsapp-accounts', $payload)->assertSessionHasNoErrors();

        $account = WhatsAppAccount::firstOrFail();
        $this->assertSame($uuid, $account->uuid);
        $this->get("/api/webhooks/whatsapp/{$uuid}?hub_mode=subscribe&hub_verify_token=my-verify-token-123&hub_challenge=12345")
            ->assertOk()->assertSee('12345');

        // The id can't be reused, must be a UUID, and can't be changed on edit.
        $this->actingAs($admin)->post('/whatsapp-accounts', $this->accountPayload(['uuid' => $uuid, 'phone_number_id' => '100000000000002']))->assertSessionHasErrors('uuid');
        $this->actingAs($admin)->post('/whatsapp-accounts', $this->accountPayload(['uuid' => 'not-a-uuid', 'phone_number_id' => '100000000000003']))->assertSessionHasErrors('uuid');
        $this->actingAs($admin)->put("/whatsapp-accounts/{$account->id}", $this->accountPayload(['uuid' => (string) \Illuminate\Support\Str::uuid()]))->assertSessionHasErrors('uuid');

        // The edit form shows the same Callback URL.
        $this->actingAs($admin)->get("/whatsapp-accounts/{$account->id}/edit")->assertSee(route('webhooks.whatsapp.verify', ['account' => $uuid]), false);

        // Adding the same number again → warning on the form + link to the existing account (its URL is the right one).
        $this->actingAs($admin)->get('/whatsapp-accounts/create')->assertSee('starts working only after you click');
        $this->actingAs($admin)->from('/whatsapp-accounts/create')
            ->post('/whatsapp-accounts', $this->accountPayload())
            ->assertSessionHasErrors('phone_number_id');
        $this->actingAs($admin)->get('/whatsapp-accounts/create')
            ->assertSee('Already added as “Sales India”', false)
            ->assertSee(route('accounts.show', $account), false);

        // A Callback URL that was never saved is unknown to the app.
        $this->get('/api/webhooks/whatsapp/'.\Illuminate\Support\Str::uuid().'?hub_mode=subscribe&hub_verify_token=my-verify-token-123&hub_challenge=1')->assertNotFound();
    }

    public function test_account_is_saved_but_marked_failed_when_meta_rejects_credentials(): void
    {
        $this->fakeMetaInvalidToken();

        $this->actingAs($this->admin())->post('/whatsapp-accounts', $this->accountPayload())
            ->assertSessionHas('warning');

        $account = WhatsAppAccount::firstOrFail();
        $this->assertSame(WhatsAppAccount::API_FAILED, $account->api_status);
        $this->assertStringContainsString('access token is invalid or expired', $account->api_status_message);
    }

    public function test_validation_rejects_bad_identifiers(): void
    {
        Http::fake();

        $this->actingAs($this->admin())->post('/whatsapp-accounts', $this->accountPayload([
            'waba_id' => 'abc',
            'phone_number_id' => '+91 98765 43210',
            'access_token' => '',
            'graph_api_version' => '26',
        ]))->assertSessionHasErrors(['waba_id', 'phone_number_id', 'access_token', 'graph_api_version']);

        $this->assertDatabaseCount('whatsapp_accounts', 0);
        Http::assertNothingSent();
    }

    public function test_phone_number_id_must_be_unique(): void
    {
        Http::fake();
        $existing = WhatsAppAccount::factory()->create();

        $this->actingAs($this->admin())->post('/whatsapp-accounts', $this->accountPayload([
            'phone_number_id' => $existing->phone_number_id,
        ]))->assertSessionHasErrors('phone_number_id');
    }

    public function test_agent_cannot_manage_accounts(): void
    {
        $account = WhatsAppAccount::factory()->create();
        $agent = $this->agent();
        $agent->whatsappAccounts()->attach($account);

        $this->actingAs($agent)->get('/whatsapp-accounts')->assertForbidden();
        $this->actingAs($agent)->get("/whatsapp-accounts/{$account->id}")->assertForbidden();
        $this->actingAs($agent)->post('/whatsapp-accounts', $this->accountPayload())->assertForbidden();
    }

    public function test_full_access_token_is_never_displayed(): void
    {
        $account = WhatsAppAccount::factory()->create(['access_token' => 'EAAGsecretTOKENvalue'.str_repeat('Z', 100).'TAIL']);
        $admin = $this->admin();

        foreach (['/whatsapp-accounts', "/whatsapp-accounts/{$account->id}", "/whatsapp-accounts/{$account->id}/edit"] as $url) {
            $this->actingAs($admin)->get($url)
                ->assertOk()
                ->assertDontSee('EAAGsecretTOKENvalue', false)
                ->assertDontSee($account->app_secret, false);
        }

        $this->actingAs($admin)->get("/whatsapp-accounts/{$account->id}")->assertSee('EAAG••••••••••••TAIL', false);
    }

    public function test_editing_with_blank_secrets_keeps_existing_values(): void
    {
        Http::fake();
        $account = WhatsAppAccount::factory()->create();
        $oldToken = $account->access_token;
        $oldSecret = $account->app_secret;

        $this->actingAs($this->admin())->put("/whatsapp-accounts/{$account->id}", [
            'name' => 'Renamed',
            'waba_id' => $account->waba_id,
            'phone_number_id' => $account->phone_number_id,
            'access_token' => '',
            'app_secret' => '',
            'webhook_verify_token' => '',
            'graph_api_version' => $account->graph_api_version,
            'is_active' => '1',
        ])->assertRedirect(route('accounts.show', $account));

        $account->refresh();
        $this->assertSame('Renamed', $account->name);
        $this->assertSame($oldToken, $account->access_token);
        $this->assertSame($oldSecret, $account->app_secret);
        Http::assertNothingSent(); // credentials unchanged → no re-check
    }

    public function test_changing_verify_token_resets_webhook_verification(): void
    {
        $account = WhatsAppAccount::factory()->connected()->create();

        $this->actingAs($this->admin())->put("/whatsapp-accounts/{$account->id}", [
            'name' => $account->name,
            'waba_id' => $account->waba_id,
            'phone_number_id' => $account->phone_number_id,
            'webhook_verify_token' => 'brand-new-token',
            'graph_api_version' => $account->graph_api_version,
            'is_active' => '1',
        ]);

        $this->assertSame(WhatsAppAccount::WEBHOOK_NOT_VERIFIED, $account->fresh()->webhook_status);
    }

    public function test_test_connection_button_updates_status(): void
    {
        $account = WhatsAppAccount::factory()->create();
        $this->fakeMetaValid($account->waba_id, $account->phone_number_id, subscribed: false);

        $this->actingAs($this->admin())->post("/whatsapp-accounts/{$account->id}/test")->assertSessionHas('success');

        $account->refresh();
        $this->assertSame(WhatsAppAccount::API_CONNECTED, $account->api_status);
        $this->assertSame(WhatsAppAccount::SUB_NOT_SUBSCRIBED, $account->subscription_status);
        $this->assertNotNull($account->api_checked_at);
    }

    public function test_subscribe_app_calls_meta(): void
    {
        $account = WhatsAppAccount::factory()->create();
        Http::fake([$this->graph("{$account->waba_id}/subscribed_apps*") => Http::response(['success' => true])]);

        $this->actingAs($this->admin())->post("/whatsapp-accounts/{$account->id}/subscribe")->assertSessionHas('success');

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), "{$account->waba_id}/subscribed_apps"));
        $this->assertSame(WhatsAppAccount::SUB_SUBSCRIBED, $account->fresh()->subscription_status);
    }

    public function test_account_can_be_disabled_and_enabled(): void
    {
        $account = WhatsAppAccount::factory()->create(['is_active' => true]);
        $admin = $this->admin();

        $this->actingAs($admin)->patch("/whatsapp-accounts/{$account->id}/toggle");
        $this->assertFalse($account->fresh()->is_active);

        $this->actingAs($admin)->patch("/whatsapp-accounts/{$account->id}/toggle");
        $this->assertTrue($account->fresh()->is_active);
    }

    public function test_delete_requires_typing_the_account_name(): void
    {
        $account = WhatsAppAccount::factory()->create(['name' => 'Support Line']);
        $admin = $this->admin();

        $this->actingAs($admin)->delete("/whatsapp-accounts/{$account->id}", ['confirm_name' => 'wrong'])
            ->assertSessionHasErrors('confirm_name');
        $this->assertModelExists($account);

        $this->actingAs($admin)->delete("/whatsapp-accounts/{$account->id}", ['confirm_name' => 'Support Line'])
            ->assertRedirect('/whatsapp-accounts');
        $this->assertModelMissing($account);
    }

    public function test_verify_token_can_be_revealed_by_admin(): void
    {
        $account = WhatsAppAccount::factory()->create(['webhook_verify_token' => 'reveal-me-123']);

        $this->actingAs($this->admin())->getJson("/whatsapp-accounts/{$account->id}/verify-token")
            ->assertOk()->assertJson(['verify_token' => 'reveal-me-123']);
    }
}
