<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Support\Permissions as P;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Payments page: Super Admin can change a payment status by hand. */
class PaymentStatusChangeTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $s = app(PlatformSettings::class);
        $s->put('billing.pkpay_enabled', '1');
        $s->put('billing.pkpay_api_key', 'pk_test_key_123');
        $s->put('billing.pkpay_api_secret', 'sk_test_secret_123456');

        $this->plan = Plan::create(['name' => 'Pro', 'slug' => 'pro', 'price' => 1500, 'currency' => 'PKR', 'billing_period' => 'monthly', 'is_active' => true]);
        $this->customer = User::factory()->withRole('Customer')->create();
    }

    private function payment(string $status = Payment::EXPIRED, array $extra = []): Payment
    {
        return Payment::create([
            'user_id' => $this->customer->id, 'plan_id' => $this->plan->id, 'plan_name' => 'Pro', 'gateway' => 'pkpay',
            'reference' => 'WA-'.fake()->unique()->numerify('######'), 'gateway_payment_id' => 'pay_01EXP',
            'amount' => 1500, 'currency' => 'PKR', 'status' => $status,
        ] + $extra);
    }

    public function test_super_admin_marks_expired_payment_paid_and_plan_is_activated(): void
    {
        $payment = $this->payment();
        $super = $this->superAdmin();

        $this->actingAs($super)->get('/payments')->assertOk()->assertSee('Change status')->assertSee('Change payment status');

        $this->actingAs($super)->post("/payments/{$payment->id}/status", [
            'status' => 'paid', 'activate_plan' => '1', 'note' => 'Received in bank, TXN 555',
        ])->assertRedirect()->assertSessionHas('success', fn ($m) => str_contains($m, 'expired → paid') && str_contains($m, 'activated'));

        $payment->refresh();
        $this->assertSame(Payment::PAID, $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame($super->id, $payment->reviewed_by);
        $this->assertStringContainsString('expired → paid — Received in bank, TXN 555', $payment->admin_note);

        $customer = $this->customer->fresh();
        $this->assertSame($this->plan->id, $customer->plan_id);
        $this->assertTrue($customer->plan_expires_at->between(now()->addMonth()->subDay(), now()->addMonth()->addDay()));
        $this->assertTrue(ActivityLog::where('action', 'billing.payment_status_changed')->exists());
    }

    public function test_plan_is_never_extended_twice_for_the_same_payment(): void
    {
        $payment = $this->payment(Payment::PENDING, ['gateway' => 'manual', 'gateway_payment_id' => null]);
        $super = $this->superAdmin();

        $this->actingAs($super)->post("/payments/{$payment->id}/approve")->assertSessionHas('success');
        $expires = $this->customer->fresh()->plan_expires_at;

        // paid → expired → paid again (with "activate" ticked) must not add another month.
        $this->actingAs($super)->post("/payments/{$payment->id}/status", ['status' => 'expired'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'plan was not changed'));
        $this->actingAs($super)->post("/payments/{$payment->id}/status", ['status' => 'paid', 'activate_plan' => '1'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'not extended again'));

        $this->assertSame(Payment::PAID, $payment->fresh()->status);
        $this->assertEquals($expires->toDateTimeString(), $this->customer->fresh()->plan_expires_at->toDateTimeString());
    }

    public function test_pkpay_paid_status_after_manual_activation_does_not_double_extend(): void
    {
        $payment = $this->payment();
        $super = $this->superAdmin();

        $this->actingAs($super)->post("/payments/{$payment->id}/status", ['status' => 'paid', 'activate_plan' => '1']);
        $this->actingAs($super)->post("/payments/{$payment->id}/status", ['status' => 'pending']);
        $expires = $this->customer->fresh()->plan_expires_at;

        // PKPay later reports it paid → markPaid, but the plan was already activated for this payment.
        Http::fake(['https://pkpay.site/api/v1/payments/pay_01EXP' => Http::response(['data' => [
            'payment_id' => 'pay_01EXP', 'order_id' => $payment->reference, 'amount' => '1500.00', 'currency' => 'PKR', 'status' => 'paid',
        ]])]);
        $this->actingAs($super)->post("/payments/{$payment->id}/sync")->assertSessionHas('success');

        $this->assertSame(Payment::PAID, $payment->fresh()->status);
        $this->assertEquals($expires->toDateTimeString(), $this->customer->fresh()->plan_expires_at->toDateTimeString());
    }

    public function test_can_mark_paid_without_activating_the_plan(): void
    {
        $payment = $this->payment(Payment::FAILED);

        $this->actingAs($this->superAdmin())->post("/payments/{$payment->id}/status", ['status' => 'paid', 'activate_plan' => '0'])
            ->assertSessionHas('success');

        $this->assertSame(Payment::PAID, $payment->fresh()->status);
        $this->assertNull($this->customer->fresh()->plan_id);
    }

    public function test_only_super_admin_can_change_a_status(): void
    {
        $payment = $this->payment();

        // Even an Admin who was given plans.manage cannot override a status.
        $admin = $this->admin();
        $admin->givePermissionTo(P::PLANS_MANAGE);
        $this->actingAs($admin)->get('/payments')->assertOk()->assertDontSee('Change status');
        $this->actingAs($admin)->post("/payments/{$payment->id}/status", ['status' => 'paid', 'activate_plan' => '1'])->assertForbidden();

        $this->actingAs($this->agent())->post("/payments/{$payment->id}/status", ['status' => 'paid'])->assertForbidden();
        $this->actingAs($this->customer)->post("/payments/{$payment->id}/status", ['status' => 'paid'])->assertForbidden();

        $this->assertSame(Payment::EXPIRED, $payment->fresh()->status);
        $this->assertNull($this->customer->fresh()->plan_id);

        // Unknown status is rejected.
        $this->actingAs($this->superAdmin())->post("/payments/{$payment->id}/status", ['status' => 'refunded'])->assertSessionHasErrors('status');
    }

    public function test_pending_set_by_super_admin_is_not_auto_expired(): void
    {
        $payment = $this->payment();
        $payment->forceFill(['created_at' => now()->subDays(3)])->save();

        $this->actingAs($this->superAdmin())->post("/payments/{$payment->id}/status", ['status' => 'pending'])->assertSessionHas('success');

        Http::fake();
        Artisan::call('payments:sync');

        $this->assertSame(Payment::PENDING, $payment->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_same_status_is_a_no_op(): void
    {
        $payment = $this->payment();

        $this->actingAs($this->superAdmin())->post("/payments/{$payment->id}/status", ['status' => 'expired'])->assertSessionHas('info');
        $this->assertNull($payment->fresh()->reviewed_by);
    }
}
