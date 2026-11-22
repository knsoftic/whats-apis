<?php

namespace Tests\Feature;

use App\Exceptions\PlanLimitException;
use App\Models\Plan;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Services\PlanGate;
use App\Support\Impersonation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesMeta;
use Tests\TestCase;

/** Users page: login as user, suspend / block; customers can't delete or swap their numbers. */
class UserAccessControlTest extends TestCase
{
    use FakesMeta, RefreshDatabase;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->plan = Plan::create([
            'name' => 'Duo', 'slug' => 'duo', 'price' => 0, 'currency' => 'PKR', 'billing_period' => 'free',
            'allow_manual' => true, 'allow_embedded_signup' => true, 'max_whatsapp_accounts' => 2, 'allow_bot_flows' => true, 'is_active' => true,
        ]);
    }

    private function customer(array $attributes = []): User
    {
        return User::factory()->withRole('Customer')->create(['plan_id' => $this->plan->id] + $attributes);
    }

    private function ownedNumber(User $customer, array $attributes = []): WhatsAppAccount
    {
        $account = WhatsAppAccount::factory()->connected()->create(['owner_id' => $customer->id] + $attributes);
        $account->users()->attach($customer->id);

        return $account;
    }

    // ------------------------------------------------------------ login as user

    public function test_super_admin_can_log_in_as_a_customer_and_return(): void
    {
        $super = $this->superAdmin();
        $customer = $this->customer(['last_login_at' => null]);

        $this->actingAs($super)->get('/users')->assertOk()->assertSee('Login as');

        $this->actingAs($super)->post("/users/{$customer->id}/impersonate")->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($customer);
        $this->assertSame($super->id, session(Impersonation::SESSION_KEY));

        $this->get('/dashboard')->assertOk()->assertSee('Logged in as')->assertSee('Return to admin');
        $this->get('/users')->assertForbidden(); // sees exactly what the customer sees
        $this->put('/password', ['current_password' => 'password', 'password' => 'NewPass123!', 'password_confirmation' => 'NewPass123!'])
            ->assertSessionHas('error');

        $this->post('/impersonate/leave')->assertRedirect('/users');
        $this->assertAuthenticatedAs($super);
        $this->assertNull(session(Impersonation::SESSION_KEY));

        $this->assertNull($customer->fresh()->last_login_at); // not a real sign-in
        $this->assertDatabaseHas('activity_logs', ['action' => 'user.impersonation_started', 'user_id' => $super->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'user.impersonation_ended', 'user_id' => $super->id]);
    }

    public function test_login_as_is_limited_to_the_super_admin_and_never_another_super_admin(): void
    {
        $super = $this->superAdmin();
        $otherSuper = $this->superAdmin();
        $customer = $this->customer();

        $this->actingAs($super)->post("/users/{$otherSuper->id}/impersonate")->assertForbidden();
        $this->actingAs($super)->post("/users/{$super->id}/impersonate")->assertForbidden();
        $this->actingAs($this->admin())->post("/users/{$customer->id}/impersonate")->assertForbidden();
        $this->actingAs($customer)->post("/users/{$super->id}/impersonate")->assertForbidden();
    }

    // ------------------------------------------------------------ suspend / block

    public function test_suspended_customer_only_sees_the_notice_and_numbers_stop(): void
    {
        $super = $this->superAdmin();
        $customer = $this->customer();
        $number = $this->ownedNumber($customer);
        $gate = app(PlanGate::class);
        $this->assertTrue($gate->botsAllowed($number));

        $this->actingAs($super)->post("/users/{$customer->id}/status", ['status' => 'suspended', 'reason' => 'Payment pending'])
            ->assertSessionHas('success');
        $this->assertSame(User::STATUS_SUSPENDED, $customer->fresh()->status);

        $this->actingAs($customer->fresh())->get('/dashboard')->assertRedirect('/account/suspended');
        $this->get('/account/suspended')->assertOk()->assertSee('Your account is suspended')->assertSee('Payment pending');
        $this->getJson('/chat/conversations')->assertForbidden();

        // Their numbers: no sending, no bots, no new numbers.
        $this->assertFalse($gate->botsAllowed($number->fresh()));
        try {
            $gate->ensureCanSend($number->fresh());
            $this->fail('A suspended customer should not be able to send.');
        } catch (PlanLimitException $e) {
            $this->assertStringContainsString('suspended', $e->getMessage());
        }

        // The Super Admin can still log in as them to review.
        $this->actingAs($super)->post("/users/{$customer->id}/impersonate");
        $this->get('/dashboard')->assertOk()->assertSee('Logged in as')->assertSee('<strong class="uppercase">suspended</strong>', false);
        $this->post('/impersonate/leave');

        // Re-activate → back to normal.
        $this->actingAs($super)->post("/users/{$customer->id}/status", ['status' => 'active'])->assertSessionHas('success');
        $this->assertNull($customer->fresh()->status_reason);
        $this->actingAs($customer->fresh())->get('/dashboard')->assertOk();
        $this->assertTrue($gate->botsAllowed($number->fresh()));
    }

    public function test_blocked_customer_is_signed_out_and_cannot_sign_in(): void
    {
        $customer = $this->customer(['password' => 'Secret123!']);

        $this->actingAs($this->superAdmin())->post("/users/{$customer->id}/status", ['status' => 'blocked', 'reason' => 'Spam'])->assertSessionHas('success');

        // Already signed in → signed out on the next request.
        $this->actingAs($customer->fresh())->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();

        // Correct password still refused.
        $this->post('/login', ['email' => $customer->email, 'password' => 'Secret123!'])
            ->assertSessionHasErrors(['email' => 'This account has been blocked. Please contact support.']);
        $this->assertGuest();
    }

    public function test_super_admins_and_yourself_cannot_be_suspended(): void
    {
        $super = $this->superAdmin();
        $other = $this->superAdmin();

        $this->actingAs($super)->post("/users/{$super->id}/status", ['status' => 'blocked'])->assertSessionHas('error');
        $this->actingAs($super)->post("/users/{$other->id}/status", ['status' => 'suspended'])->assertSessionHas('error');
        $this->actingAs($this->admin())->post("/users/{$other->id}/status", ['status' => 'blocked'])->assertForbidden();
        $this->assertSame(User::STATUS_ACTIVE, $other->fresh()->status);
    }

    // ------------------------------------------------------------ customers' numbers

    public function test_customer_cannot_delete_or_swap_a_connected_number_but_admin_can_remove_it(): void
    {
        $customer = $this->customer();
        $number = $this->ownedNumber($customer, ['name' => 'Shop', 'waba_id' => '200000000000001', 'phone_number_id' => '100000000000001']);

        // No delete for the customer (UI + server).
        $this->actingAs($customer)->get("/whatsapp-accounts/{$number->id}")->assertOk()
            ->assertDontSee('Danger zone')->assertSee('can’t be removed or swapped', false);
        $this->actingAs($customer)->delete("/whatsapp-accounts/{$number->id}", ['confirm_name' => 'Shop'])->assertForbidden();
        $this->assertModelExists($number);

        // Can't point the account to another number…
        $this->actingAs($customer)->put("/whatsapp-accounts/{$number->id}", $this->accountPayload([
            'name' => 'Shop', 'waba_id' => '200000000000001', 'phone_number_id' => '199999999999999', 'access_token' => '', 'app_secret' => '', 'webhook_verify_token' => '',
        ]))->assertSessionHasErrors('phone_number_id');
        $this->assertSame('100000000000001', $number->fresh()->phone_number_id);

        // …but can rename it / refresh the token.
        $this->actingAs($customer)->get("/whatsapp-accounts/{$number->id}/edit")->assertOk()->assertSee('The number can\'t be changed', false);
        $this->actingAs($customer)->put("/whatsapp-accounts/{$number->id}", $this->accountPayload([
            'name' => 'Shop Karachi', 'waba_id' => '200000000000001', 'phone_number_id' => '100000000000001', 'access_token' => '', 'app_secret' => '', 'webhook_verify_token' => '',
        ]))->assertSessionHasNoErrors();
        $this->assertSame('Shop Karachi', $number->fresh()->name);

        // The admin can remove it (frees a slot on the plan).
        $this->actingAs($this->superAdmin())->get("/whatsapp-accounts/{$number->id}")->assertSee('Danger zone')->assertSee('frees one number slot');
        $this->actingAs($this->superAdmin())->delete("/whatsapp-accounts/{$number->id}", ['confirm_name' => 'Shop Karachi'])->assertRedirect();
        $this->assertModelMissing($number);
    }

    public function test_plan_limit_of_two_numbers_blocks_a_third(): void
    {
        $customer = $this->customer();
        $this->ownedNumber($customer);
        $this->ownedNumber($customer);

        $this->actingAs($customer)->get('/whatsapp-accounts')->assertOk()
            ->assertSee('2 / 2')->assertSee('You can\'t add more', false)
            ->assertDontSee(route('accounts.create'), false)
            ->assertDontSee('@else', false)
            ->assertDontSee('does not allow adding WhatsApp numbers');

        $this->actingAs($customer)->get('/whatsapp-accounts/create')->assertRedirect('/whatsapp-accounts')
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'allows 2 WhatsApp number(s)'));

        $payload = $this->accountPayload(['phone_number_id' => '100000000000077']);
        $this->actingAs($customer)->post('/whatsapp-accounts', $payload);
        $this->assertSame(2, $customer->ownedAccounts()->count());
    }
}
