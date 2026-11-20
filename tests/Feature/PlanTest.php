<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Plan;
use App\Models\User;
use App\Models\WhatsAppAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesMeta;
use Tests\TestCase;

class PlanTest extends TestCase
{
    use FakesMeta, RefreshDatabase;

    private function plan(array $overrides = []): Plan
    {
        return Plan::create(array_merge([
            'name' => 'Test', 'slug' => 'test-'.uniqid(), 'price' => 0, 'currency' => 'INR', 'billing_period' => 'monthly',
            'allow_manual' => true, 'allow_embedded_signup' => true, 'max_whatsapp_accounts' => 2,
            'daily_message_limit' => 3, 'max_contacts' => 5, 'allow_bot_flows' => true, 'max_bot_flows' => 2, 'is_active' => true,
        ], $overrides));
    }

    private function customer(?Plan $plan = null, array $attributes = []): User
    {
        $user = User::factory()->withRole('Customer')->create(array_merge(['plan_id' => $plan?->id], $attributes));

        return $user->fresh();
    }

    private function ownedAccount(User $owner): WhatsAppAccount
    {
        $account = WhatsAppAccount::factory()->connected()->create();
        $account->forceFill(['owner_id' => $owner->id])->save();
        $account->users()->attach($owner);

        return $account;
    }

    public function test_only_super_admin_manages_plans(): void
    {
        $this->actingAs($this->admin())->get('/plans')->assertForbidden();
        $this->actingAs($this->customer())->get('/plans')->assertForbidden();

        $this->actingAs($this->superAdmin())->post('/plans', [
            'name' => 'Gold', 'price' => '1499', 'currency' => 'INR', 'billing_period' => 'monthly',
            'allow_manual' => '1', 'allow_embedded_signup' => '0', 'allow_bot_flows' => '1',
            'max_whatsapp_accounts' => '5', 'daily_message_limit' => '', 'is_active' => '1', 'is_default' => '1',
        ])->assertRedirect('/plans');

        $gold = Plan::where('slug', 'gold')->firstOrFail();
        $this->assertFalse($gold->allow_embedded_signup);
        $this->assertNull($gold->daily_message_limit); // empty = unlimited
        $this->assertTrue($gold->is_default);
    }

    public function test_only_one_default_plan(): void
    {
        $a = $this->plan(['is_default' => true]);
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post('/plans', ['name' => 'New default', 'price' => '0', 'currency' => 'INR', 'billing_period' => 'free', 'is_active' => '1', 'is_default' => '1']);

        $this->assertFalse($a->fresh()->is_default);
    }

    public function test_customer_sees_only_own_numbers_and_new_number_is_owned_by_them(): void
    {
        $customer = $this->customer($this->plan());
        $mine = $this->ownedAccount($customer);
        WhatsAppAccount::factory()->create(['name' => 'Someone Else Line']);

        $this->actingAs($customer)->get('/whatsapp-accounts')->assertOk()->assertSee($mine->name)->assertDontSee('Someone Else Line');

        $payload = $this->accountPayload(['phone_number_id' => '100000000000555']);
        $this->fakeMetaValid($payload['waba_id'], $payload['phone_number_id']);
        $this->actingAs($customer)->post('/whatsapp-accounts', $payload)->assertRedirect();

        $new = WhatsAppAccount::where('phone_number_id', '100000000000555')->firstOrFail();
        $this->assertSame($customer->id, $new->owner_id);
        $this->assertTrue($customer->canAccessAccount($new));
    }

    public function test_plan_blocks_manual_numbers_when_not_included(): void
    {
        $customer = $this->customer($this->plan(['allow_manual' => false]));

        $this->actingAs($customer)->get('/whatsapp-accounts/create')->assertRedirect('/whatsapp-accounts')->assertSessionHas('error');
        $this->actingAs($customer)->post('/whatsapp-accounts', $this->accountPayload())->assertSessionHas('error');
        $this->assertDatabaseCount('whatsapp_accounts', 0);
    }

    public function test_max_numbers_limit(): void
    {
        $customer = $this->customer($this->plan(['max_whatsapp_accounts' => 1]));
        $this->ownedAccount($customer);

        $this->actingAs($customer)->post('/whatsapp-accounts', $this->accountPayload())->assertSessionHas('error', fn ($m) => str_contains($m, 'allows 1 WhatsApp number'));
    }

    public function test_customer_cannot_set_an_owner(): void
    {
        $customer = $this->customer($this->plan());

        $this->actingAs($customer)->post('/whatsapp-accounts', $this->accountPayload(['owner_id' => 1]))->assertSessionHasErrors('owner_id');
    }

    public function test_daily_message_limit_blocks_sending(): void
    {
        $customer = $this->customer($this->plan(['daily_message_limit' => 2]));
        $account = $this->ownedAccount($customer);
        $conversation = Conversation::factory()->withinWindow()->create([
            'contact_id' => Contact::factory()->create(['whatsapp_account_id' => $account->id])->id,
        ]);
        Http::fake([$this->graph($account->phone_number_id.'/messages*') => $this->metaSendOkEachTime()]);

        $this->actingAs($customer)->postJson("/chat/conversations/{$conversation->id}/messages", ['body' => 'one'])->assertCreated();
        $this->actingAs($customer)->postJson("/chat/conversations/{$conversation->id}/messages", ['body' => 'two'])->assertCreated();
        $this->actingAs($customer)->postJson("/chat/conversations/{$conversation->id}/messages", ['body' => 'three'])
            ->assertStatus(422)->assertJsonPath('reason', 'plan_limit');

        $this->assertSame(2, Message::where('direction', 'outbound')->count());

        // Next day the counter resets (customer wrote again, so the 24h window is open).
        $this->travel(1)->days();
        $conversation->update(['last_inbound_at' => now()->subMinutes(5)]);
        $this->actingAs($customer)->postJson("/chat/conversations/{$conversation->id}/messages", ['body' => 'tomorrow'])->assertCreated();
    }

    public function test_expired_plan_blocks_sending(): void
    {
        $customer = $this->customer($this->plan(), ['plan_expires_at' => now()->subDay()]);
        $account = $this->ownedAccount($customer);
        $conversation = Conversation::factory()->withinWindow()->create([
            'contact_id' => Contact::factory()->create(['whatsapp_account_id' => $account->id])->id,
        ]);
        Http::fake();

        $this->actingAs($customer)->postJson("/chat/conversations/{$conversation->id}/messages", ['body' => 'hi'])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'expired'));
        Http::assertNothingSent();
    }

    public function test_platform_owned_numbers_are_unlimited(): void
    {
        $admin = $this->admin();
        $account = WhatsAppAccount::factory()->connected()->create(); // no owner
        $conversation = Conversation::factory()->withinWindow()->create([
            'contact_id' => Contact::factory()->create(['whatsapp_account_id' => $account->id])->id,
        ]);
        Http::fake([$this->graph($account->phone_number_id.'/messages*') => $this->metaSendOkEachTime()]);

        foreach (range(1, 5) as $i) {
            $this->actingAs($admin)->postJson("/chat/conversations/{$conversation->id}/messages", ['body' => "m{$i}"])->assertCreated();
        }
    }

    public function test_contact_limit(): void
    {
        $customer = $this->customer($this->plan(['max_contacts' => 1]));
        $account = $this->ownedAccount($customer);
        Contact::factory()->create(['whatsapp_account_id' => $account->id]);

        $this->actingAs($customer)->post('/contacts', [
            'whatsapp_account_id' => $account->id, 'phone' => '+919811100000', 'opt_in_status' => 'unknown',
        ])->assertSessionHas('error', fn ($m) => str_contains($m, 'allows 1 contacts'));
    }

    public function test_super_admin_assigns_plan_and_owner(): void
    {
        $super = $this->superAdmin();
        $plan = $this->plan();
        $customer = $this->customer();
        $account = WhatsAppAccount::factory()->create();

        $this->actingAs($super)->put("/users/{$customer->id}", [
            'name' => $customer->name, 'email' => $customer->email, 'role' => 'Customer', 'is_active' => '1',
            'plan_id' => $plan->id, 'plan_expires_at' => now()->addMonth()->toDateString(),
        ])->assertRedirect('/users');
        $this->assertSame($plan->id, $customer->fresh()->plan_id);

        $this->actingAs($super)->put("/whatsapp-accounts/{$account->id}", [
            'name' => $account->name, 'waba_id' => $account->waba_id, 'phone_number_id' => $account->phone_number_id,
            'graph_api_version' => 'v26.0', 'is_active' => '1', 'owner_id' => $customer->id,
        ])->assertRedirect();

        $this->assertSame($customer->id, $account->fresh()->owner_id);
        $this->assertTrue($customer->fresh()->canAccessAccount($account));
    }
}
