<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\PlatformSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Self-purchase of plans: start a payment, confirm it (PKPay callback /
 * status check / admin approval) and activate the plan exactly once.
 */
class PaymentService
{
    public function __construct(
        private readonly PlatformSettings $settings,
        private readonly PkPayClient $pkpay,
        private readonly ActivityLogger $logger,
    ) {}

    public function selfPurchaseEnabled(): bool
    {
        return $this->settings->bool('billing.self_purchase');
    }

    /**
     * @return array<string, string> method => label
     */
    public function methodsFor(Plan $plan): array
    {
        $methods = [];

        if ($this->pkpay->isEnabled() && strtoupper($plan->currency) === 'PKR') {
            $methods[Payment::GATEWAY_PKPAY] = 'PKPay — pay with any bank / wallet app (Raast QR)';
        }

        if ($this->settings->bool('billing.manual_enabled')) {
            $methods[Payment::GATEWAY_MANUAL] = 'Bank transfer / wallet (manual approval)';
        }

        return $methods;
    }

    /**
     * Creates the payment and, for PKPay, the gateway checkout.
     */
    public function start(User $user, Plan $plan, string $method): Payment
    {
        if (! $this->selfPurchaseEnabled()) {
            throw new PaymentGatewayException('Online plan purchase is not enabled. Please contact the administrator.');
        }

        if (! $plan->is_active || (float) $plan->price <= 0) {
            throw new PaymentGatewayException('This plan cannot be purchased.');
        }

        if (! array_key_exists($method, $this->methodsFor($plan))) {
            throw new PaymentGatewayException($method === Payment::GATEWAY_PKPAY && strtoupper($plan->currency) !== 'PKR'
                ? 'PKPay accepts PKR only — this plan is priced in '.$plan->currency.'.'
                : 'This payment method is not available.');
        }

        if ($method === Payment::GATEWAY_PKPAY && ((float) $plan->price < PkPayClient::MIN_AMOUNT || (float) $plan->price > PkPayClient::MAX_AMOUNT)) {
            throw new PaymentGatewayException('PKPay accepts amounts from PKR 1 to PKR 500,000.');
        }

        $payment = Payment::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'gateway' => $method,
            'reference' => 'WA'.now()->format('ymd').'-'.Str::upper(Str::random(10)),
            'amount' => $plan->price,
            'currency' => strtoupper($plan->currency),
            'status' => Payment::PENDING,
        ]);

        if ($method === Payment::GATEWAY_PKPAY) {
            $this->createPkPayCheckout($payment, $user, $plan);
        }

        $this->logger->log('billing.payment_started', "Started {$method} payment {$payment->reference} for plan {$plan->name}", $payment, ['amount' => $payment->amountLabel()], user: $user);

        return $payment;
    }

    private function createPkPayCheckout(Payment $payment, User $user, Plan $plan): void
    {
        try {
            $data = $this->pkpay->createPayment(array_filter([
                'amount' => round((float) $payment->amount, 2),
                'order_id' => $payment->reference,
                'description' => Str::limit($this->settings->siteName().' — '.$plan->name.' plan', 120, ''),
                'customer' => array_filter(['name' => $user->name, 'email' => $user->email, 'phone' => $user->phone]),
                'callback_url' => route('payments.pkpay.callback'),
                'return_url' => route('billing.payment', $payment),
                'metadata' => ['payment_id' => (string) $payment->id, 'plan' => $plan->slug],
            ]), 'order-'.$payment->reference);
        } catch (PaymentGatewayException $e) {
            $payment->forceFill(['status' => Payment::FAILED, 'admin_note' => $e->getMessage()])->save();

            throw $e;
        }

        $payment->forceFill([
            'gateway_payment_id' => $data['payment_id'] ?? null,
            'gateway_transaction_id' => $data['transaction_id'] ?? null,
            'checkout_url' => $data['checkout_url'] ?? ($data['short_url'] ?? null),
            'expires_at' => isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : now()->addMinutes(5),
            'meta' => ['created' => self::safe($data)],
        ])->save();

        if (! $payment->checkout_url) {
            $payment->forceFill(['status' => Payment::FAILED])->save();

            throw new PaymentGatewayException('PKPay did not return a checkout link.');
        }
    }

    /**
     * Applies a PKPay payment object (from a verified callback or a status check).
     *
     * @param  array<string, mixed>  $data
     */
    public function applyPkPayData(Payment $payment, array $data, string $source): Payment
    {
        if ($payment->isPaid()) {
            return $payment; // idempotent
        }

        // Never trust a payment object that doesn't match our order.
        $amountOk = abs((float) ($data['amount'] ?? -1) - (float) $payment->amount) < 0.01;
        $currencyOk = strtoupper((string) ($data['currency'] ?? 'PKR')) === $payment->currency;
        $orderOk = (string) ($data['order_id'] ?? '') === $payment->reference;

        if (! $amountOk || ! $currencyOk || ! $orderOk) {
            Log::warning('PKPay data does not match payment', ['payment' => $payment->id, 'source' => $source]);
            $line = 'PKPay '.$source.' did not match amount / currency / order id.';
            if (! str_contains((string) $payment->admin_note, $line)) {
                $payment->forceFill(['admin_note' => trim(($payment->admin_note ? $payment->admin_note."
" : '').$line)])->save();
            }

            return $payment;
        }

        $status = strtolower((string) ($data['status'] ?? ''));
        $meta = $payment->meta ?? [];
        $meta[$source] = self::safe($data);

        $payment->forceFill([
            'gateway_payment_id' => $payment->gateway_payment_id ?: ($data['payment_id'] ?? null),
            'gateway_transaction_id' => $data['transaction_id'] ?? $payment->gateway_transaction_id,
            'meta' => $meta,
        ]);

        if ($status === 'paid') {
            return $this->markPaid($payment, isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : now());
        }

        if (in_array($status, [Payment::FAILED, Payment::EXPIRED, Payment::CANCELLED], true) && $payment->isPending()) {
            $payment->status = $status;
        }

        $payment->save();

        return $payment;
    }

    public function syncPkPay(Payment $payment): Payment
    {
        if ($payment->gateway !== Payment::GATEWAY_PKPAY || ! $payment->gateway_payment_id || $payment->isPaid()) {
            return $payment;
        }

        return $this->applyPkPayData($payment, $this->pkpay->getPayment($payment->gateway_payment_id), 'status_check');
    }

    /**
     * Marks the payment paid and activates the plan (exactly once).
     */
    public function markPaid(Payment $payment, ?Carbon $paidAt = null, ?User $reviewer = null): Payment
    {
        return DB::transaction(function () use ($payment, $paidAt, $reviewer) {
            $fresh = Payment::whereKey($payment->id)->lockForUpdate()->first();

            if ($fresh->isPaid()) {
                return $fresh;
            }

            $meta = [...($fresh->meta ?? []), ...($payment->meta ?? [])];
            // Activated before (paid → changed by an admin → paid again)? Never extend the plan twice.
            $alreadyActivated = ! empty($fresh->meta['plan_activated_at']);
            $meta['plan_activated_at'] = $fresh->meta['plan_activated_at'] ?? now()->toIso8601String();

            $fresh->forceFill([
                'status' => Payment::PAID,
                'paid_at' => $paidAt ?? now(),
                'reviewed_by' => $reviewer?->id ?? $fresh->reviewed_by,
                'meta' => $meta,
                'gateway_transaction_id' => $payment->gateway_transaction_id ?? $fresh->gateway_transaction_id,
            ])->save();

            if (! $alreadyActivated) {
                $this->activatePlan($fresh);
            }

            return $fresh;
        });
    }

    public function reject(Payment $payment, User $reviewer, ?string $note = null): void
    {
        if (! $payment->isPending()) {
            return;
        }

        $payment->forceFill(['status' => Payment::REJECTED, 'reviewed_by' => $reviewer->id, 'admin_note' => $note])->save();
        $this->logger->log('billing.payment_rejected', "Rejected payment {$payment->reference}", $payment);
    }

    public const ADMIN_STATUSES = [Payment::PAID, Payment::PENDING, Payment::EXPIRED, Payment::FAILED, Payment::CANCELLED, Payment::REJECTED];

    /**
     * Super Admin override: set any status by hand (e.g. an expired PKPay payment
     * that was actually received). Marking it paid can activate the plan; the
     * plan is activated at most once per payment. Moving away from "paid" never
     * removes the customer's plan automatically.
     *
     * @return bool whether the plan was activated by this change
     */
    public function changeStatus(Payment $payment, string $status, User $admin, ?string $note = null, bool $activatePlan = true): bool
    {
        if (! in_array($status, self::ADMIN_STATUSES, true)) {
            throw new PaymentGatewayException('Unknown payment status.');
        }

        return DB::transaction(function () use ($payment, $status, $admin, $note, $activatePlan) {
            $fresh = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $from = $fresh->status;

            if ($from === $status) {
                return false;
            }

            $line = now()->format('Y-m-d H:i').' · '.$admin->name.': '.$from.' → '.$status.($note ? ' — '.$note : '');
            $meta = $fresh->meta ?? [];

            // A paid payment always had its plan activated (markPaid) — remember that before leaving "paid".
            if ($from === Payment::PAID && empty($meta['plan_activated_at'])) {
                $meta['plan_activated_at'] = ($fresh->paid_at ?? now())->toIso8601String();
            }

            $alreadyActivated = ! empty($meta['plan_activated_at']);
            $activate = $status === Payment::PAID && $activatePlan && ! $alreadyActivated;

            if ($activate) {
                $meta['plan_activated_at'] = now()->toIso8601String();
            }

            $fresh->forceFill([
                'status' => $status,
                'paid_at' => $status === Payment::PAID ? ($fresh->paid_at ?? now()) : null,
                'reviewed_by' => $admin->id,
                'admin_note' => Str::limit(trim(($fresh->admin_note ? $fresh->admin_note."\n" : '').$line), 2000, ''),
                'meta' => $meta,
            ])->save();

            if ($activate) {
                $this->activatePlan($fresh);
            }

            $this->logger->log('billing.payment_status_changed', "Payment {$fresh->reference}: {$from} → {$status}".($activate ? ' (plan activated)' : ''), $fresh, [
                'from' => $from, 'to' => $status, 'note' => $note, 'plan_activated' => $activate,
            ]);

            $payment->setRawAttributes($fresh->getAttributes(), true);

            return $activate;
        });
    }

    /**
     * New expiry = (current expiry if renewing the same, still-valid plan, else now) + billing period.
     */
    public function activatePlan(Payment $payment): void
    {
        $user = $payment->user;
        $plan = $payment->plan;

        if (! $user || ! $plan) {
            return;
        }

        $base = ($user->plan_id === $plan->id && $user->plan_expires_at?->isFuture()) ? $user->plan_expires_at->copy() : now();

        $expires = match ($plan->billing_period) {
            'monthly' => $base->addMonthNoOverflow(),
            'yearly' => $base->addYearNoOverflow(),
            default => null, // lifetime / free → no expiry
        };

        $user->forceFill(['plan_id' => $plan->id, 'plan_expires_at' => $expires])->save();

        $this->logger->log('billing.plan_activated', "Plan {$plan->name} activated for {$user->email} (payment {$payment->reference})", $payment, [
            'expires_at' => $expires?->toDateString(),
        ], user: $user);
    }

    public function switchToFree(User $user, Plan $plan): void
    {
        if (! $plan->is_active || (float) $plan->price > 0) {
            throw new PaymentGatewayException('Only active free plans can be selected without payment.');
        }

        $user->forceFill(['plan_id' => $plan->id, 'plan_expires_at' => null])->save();
        $this->logger->log('billing.free_plan', "Switched to free plan {$plan->name}", $user, user: $user);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function safe(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'payment_id', 'transaction_id', 'order_id', 'amount', 'currency', 'fee', 'net_amount', 'status',
            'expires_at', 'paid_at', 'created_at', 'sandbox',
        ]));
    }
}
