<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'sk_test_secret_123456';

    private Plan $plan;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $s = app(PlatformSettings::class);
        $s->put('billing.self_purchase', '1');
        $s->put('billing.pkpay_enabled', '1');
        $s->put('billing.pkpay_api_key', 'pk_test_key_123');
        $s->put('billing.pkpay_api_secret', $this->secret);
        $s->put('billing.manual_enabled', '1');
        $s->put('billing.manual_instructions', 'Meezan Bank IBAN PK00TEST');

        $this->plan = Plan::create([
            'name' => 'Pro', 'slug' => 'pro', 'price' => 1500, 'currency' => 'PKR', 'billing_period' => 'monthly',
            'allow_manual' => true, 'allow_embedded_signup' => true, 'is_active' => true,
        ]);
        $this->customer = User::factory()->withRole('Customer')->create();
    }

    private function fakePkPayCreate(string $paymentId = 'pay_01TEST'): void
    {
        Http::fake(['https://pkpay.site/api/v1/payments' => function (Request $r) use ($paymentId) {
            $body = json_decode($r->body(), true);

            return Http::response(['data' => [
                'payment_id' => $paymentId, 'transaction_id' => 'TXN1', 'order_id' => $body['order_id'],
                'amount' => number_format($body['amount'], 2, '.', ''), 'currency' => 'PKR', 'status' => 'pending',
                'checkout_url' => "https://pkpay.site/checkout/{$paymentId}", 'expires_at' => now()->addMinutes(5)->toIso8601String(),
            ]], 201);
        }]);
    }

    private function pkpayCallback(array $data, ?string $secret = null, array $extra = []): TestResponse
    {
        $raw = json_encode(['event' => 'payment.'.$data['status'], 'data' => $data, 'sent_at' => now()->toIso8601String()] + $extra);
        $ts = (string) time();

        return $this->call('POST', '/api/payments/pkpay/callback', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_TIMESTAMP' => $ts,
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $ts.'.'.$raw, $secret ?? $this->secret),
        ], $raw);
    }

    private function startPkPay(): Payment
    {
        $this->fakePkPayCreate();
        $this->actingAs($this->customer)->post("/billing/checkout/{$this->plan->id}", ['method' => 'pkpay'])
            ->assertRedirect('https://pkpay.site/checkout/pay_01TEST');

        return Payment::firstOrFail();
    }

    public function test_pkpay_checkout_sends_a_correctly_signed_request(): void
    {
        $payment = $this->startPkPay();

        $this->assertSame(Payment::PENDING, $payment->status);
        $this->assertSame('pay_01TEST', $payment->gateway_payment_id);
        $this->assertSame('1500.00', (string) $payment->amount);

        Http::assertSent(function (Request $r) use ($payment) {
            $expected = hash_hmac('sha256', $r->header('X-Timestamp')[0]."\nPOST\n/api/v1/payments\n".$r->body(), $this->secret);

            return $r->method() === 'POST'
                && $r->header('X-Api-Key')[0] === 'pk_test_key_123'
                && $r->header('X-Signature')[0] === $expected
                && $r->header('Idempotency-Key')[0] === 'order-'.$payment->reference
                && $r['order_id'] === $payment->reference
                && (float) $r['amount'] === 1500.0
                && str_ends_with($r['callback_url'], '/api/payments/pkpay/callback')
                && str_contains($r['return_url'], "/billing/payments/{$payment->id}");
        });
    }

    public function test_paid_callback_activates_the_plan_once(): void
    {
        $payment = $this->startPkPay();
        $data = ['payment_id' => 'pay_01TEST', 'transaction_id' => 'TXN1', 'order_id' => $payment->reference, 'amount' => '1500.00', 'currency' => 'PKR', 'status' => 'paid', 'paid_at' => now()->toIso8601String()];

        $this->pkpayCallback($data)->assertOk();

        $payment->refresh();
        $this->assertSame(Payment::PAID, $payment->status);
        $user = $this->customer->fresh();
        $this->assertSame($this->plan->id, $user->plan_id);
        $this->assertTrue($user->plan_expires_at->between(now()->addMonth()->subDay(), now()->addMonth()->addDay()));

        // Retried callback → no double extension
        $expiry = $user->plan_expires_at;
        $this->pkpayCallback($data)->assertOk();
        $this->assertEquals($expiry, $this->customer->fresh()->plan_expires_at);
        $this->assertDatabaseHas('activity_logs', ['action' => 'billing.plan_activated']);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $payment = $this->startPkPay();

        $this->pkpayCallback(['order_id' => $payment->reference, 'amount' => '1500.00', 'currency' => 'PKR', 'status' => 'paid'], secret: 'sk_wrong')
            ->assertStatus(401);

        $this->assertSame(Payment::PENDING, $payment->fresh()->status);
        $this->assertNull($this->customer->fresh()->plan_id);
    }

    public function test_amount_mismatch_never_activates(): void
    {
        $payment = $this->startPkPay();

        $this->pkpayCallback(['payment_id' => 'pay_01TEST', 'order_id' => $payment->reference, 'amount' => '15.00', 'currency' => 'PKR', 'status' => 'paid'])->assertOk();

        $this->assertSame(Payment::PENDING, $payment->fresh()->status);
        $this->assertNull($this->customer->fresh()->plan_id);
    }

    public function test_sandbox_callback_is_ignored(): void
    {
        $payment = $this->startPkPay();

        $this->pkpayCallback(['order_id' => $payment->reference, 'amount' => '1500.00', 'currency' => 'PKR', 'status' => 'paid', 'sandbox' => true])
            ->assertOk()->assertSeeText('SANDBOX_IGNORED');

        $this->assertSame(Payment::PENDING, $payment->fresh()->status);
    }

    public function test_return_page_checks_status_with_pkpay(): void
    {
        $payment = $this->startPkPay();
        Http::fake(['https://pkpay.site/api/v1/payments/pay_01TEST' => Http::response(['data' => [
            'payment_id' => 'pay_01TEST', 'order_id' => $payment->reference, 'amount' => '1500.00', 'currency' => 'PKR', 'status' => 'paid',
        ]])]);

        $this->actingAs($this->customer)->get("/billing/payments/{$payment->id}")->assertOk()->assertSee('Payment received');

        $this->assertSame($this->plan->id, $this->customer->fresh()->plan_id);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && $r->header('X-Signature')[0] === hash_hmac('sha256', $r->header('X-Timestamp')[0]."\nGET\n/api/v1/payments/pay_01TEST\n", $this->secret));
    }

    public function test_sync_command_marks_expired_payments(): void
    {
        $payment = $this->startPkPay();
        Http::fake(['https://pkpay.site/api/v1/payments/pay_01TEST' => Http::response(['data' => [
            'payment_id' => 'pay_01TEST', 'order_id' => $payment->reference, 'amount' => '1500.00', 'currency' => 'PKR', 'status' => 'expired',
        ]])]);

        Artisan::call('payments:sync');

        $this->assertSame(Payment::EXPIRED, $payment->fresh()->status);
    }

    public function test_renewal_extends_from_current_expiry(): void
    {
        $this->customer->update(['plan_id' => $this->plan->id, 'plan_expires_at' => now()->addDays(10)]);
        $payment = $this->startPkPay();

        $this->pkpayCallback(['payment_id' => 'pay_01TEST', 'order_id' => $payment->reference, 'amount' => '1500.00', 'currency' => 'PKR', 'status' => 'paid']);

        $this->assertTrue($this->customer->fresh()->plan_expires_at->between(now()->addDays(10)->addMonth()->subDay(), now()->addDays(10)->addMonth()->addDay()));
    }

    public function test_manual_payment_is_activated_by_admin_approval(): void
    {
        Http::fake();
        $this->actingAs($this->customer)->post("/billing/checkout/{$this->plan->id}", ['method' => 'manual'])->assertRedirect();
        $payment = Payment::firstOrFail();

        $this->actingAs($this->customer)->get("/billing/payments/{$payment->id}")->assertOk()->assertSee('Meezan Bank IBAN PK00TEST');
        $this->actingAs($this->customer)->post("/billing/payments/{$payment->id}/manual", ['reference' => 'TID 998877'])->assertSessionHas('success');

        $this->actingAs($this->admin())->post("/payments/{$payment->id}/approve")->assertForbidden(); // Admin lacks plans.manage
        $super = $this->superAdmin();
        $this->actingAs($super)->get('/payments')->assertOk()->assertSee('TID 998877');
        $this->actingAs($super)->post("/payments/{$payment->id}/approve")->assertSessionHas('success');

        $this->assertSame(Payment::PAID, $payment->fresh()->status);
        $this->assertSame($this->plan->id, $this->customer->fresh()->plan_id);
        Http::assertNothingSent();
    }

    public function test_non_pkr_plan_cannot_use_pkpay_and_self_purchase_switch_works(): void
    {
        Http::fake();
        $usd = Plan::create(['name' => 'Global', 'slug' => 'global', 'price' => 10, 'currency' => 'USD', 'billing_period' => 'monthly', 'is_active' => true]);

        $this->actingAs($this->customer)->post("/billing/checkout/{$usd->id}", ['method' => 'pkpay'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'PKR only'));

        app(PlatformSettings::class)->put('billing.self_purchase', '0');
        $this->actingAs($this->customer)->get("/billing/checkout/{$this->plan->id}")->assertRedirect('/billing');
        Http::assertNothingSent();
    }

    public function test_customer_can_switch_to_a_free_plan_and_cannot_see_others_payments(): void
    {
        $free = Plan::create(['name' => 'Free', 'slug' => 'free', 'price' => 0, 'currency' => 'PKR', 'billing_period' => 'free', 'is_active' => true]);
        $this->actingAs($this->customer)->post("/billing/plans/{$free->id}/free")->assertRedirect('/billing');
        $this->assertSame($free->id, $this->customer->fresh()->plan_id);

        $other = User::factory()->withRole('Customer')->create();
        $theirs = Payment::create(['user_id' => $other->id, 'plan_id' => $this->plan->id, 'gateway' => 'manual', 'reference' => 'WA-OTHER', 'amount' => 1, 'currency' => 'PKR']);
        $this->actingAs($this->customer)->get("/billing/payments/{$theirs->id}")->assertNotFound();
        $this->actingAs($this->agent())->get('/billing')->assertForbidden();
    }
}
