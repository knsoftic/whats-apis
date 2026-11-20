<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Plan;
use App\Services\Payments\PaymentGatewayException;
use App\Services\Payments\PaymentService;
use App\Services\PlanGate;
use App\Support\Permissions;
use App\Support\PlatformSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Customer billing: current plan, buy / upgrade, payment status, history.
 */
class BillingController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly PlatformSettings $settings,
    ) {}

    private function ensureCustomer(Request $request): void
    {
        abort_unless($request->user()->hasRole(Permissions::ROLE_CUSTOMER), 403);
    }

    public function index(Request $request, PlanGate $gate): View
    {
        $this->ensureCustomer($request);
        $user = $request->user();

        return view('billing.index', [
            'user' => $user,
            'usage' => $gate->usage($user),
            'plans' => Plan::active()->orderBy('sort_order')->orderBy('price')->get(),
            'selfPurchase' => $this->payments->selfPurchaseEnabled(),
            'history' => Payment::where('user_id', $user->id)->latest('id')->limit(20)->get(),
        ]);
    }

    public function checkout(Request $request, Plan $plan): View|RedirectResponse
    {
        $this->ensureCustomer($request);

        if (! $this->payments->selfPurchaseEnabled() || ! $plan->is_active || (float) $plan->price <= 0) {
            return redirect()->route('billing.index')->with('error', 'This plan cannot be purchased online right now.');
        }

        return view('billing.checkout', [
            'plan' => $plan,
            'methods' => $this->payments->methodsFor($plan),
        ]);
    }

    public function start(Request $request, Plan $plan): RedirectResponse
    {
        $this->ensureCustomer($request);
        $data = $request->validate(['method' => ['required', 'in:pkpay,manual']]);

        try {
            $payment = $this->payments->start($request->user(), $plan, $data['method']);
        } catch (PaymentGatewayException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($payment->gateway === Payment::GATEWAY_PKPAY) {
            return redirect()->away($payment->checkout_url);
        }

        return redirect()->route('billing.payment', $payment);
    }

    public function free(Request $request, Plan $plan): RedirectResponse
    {
        $this->ensureCustomer($request);

        try {
            $this->payments->switchToFree($request->user(), $plan);
        } catch (PaymentGatewayException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('billing.index')->with('success', "You are now on the {$plan->name} plan.");
    }

    /**
     * Return page after PKPay and the page for manual payments.
     */
    public function payment(Request $request, Payment $payment): View
    {
        $this->authorizePayment($request, $payment);

        // Coming back from PKPay before the callback arrived → ask PKPay directly.
        if ($payment->gateway === Payment::GATEWAY_PKPAY && $payment->isPending() && $payment->gateway_payment_id) {
            try {
                $payment = $this->payments->syncPkPay($payment);
            } catch (PaymentGatewayException) {
                // keep showing "pending"; the callback / scheduler will update it
            }
        }

        return view('billing.payment', [
            'payment' => $payment->fresh('plan'),
            'instructions' => $this->settings->get('billing.manual_instructions'),
        ]);
    }

    public function status(Request $request, Payment $payment): JsonResponse
    {
        $this->authorizePayment($request, $payment);

        return response()->json(['status' => $payment->fresh()->status]);
    }

    public function submitManual(Request $request, Payment $payment): RedirectResponse
    {
        $this->authorizePayment($request, $payment);
        abort_unless($payment->gateway === Payment::GATEWAY_MANUAL && $payment->isPending(), 404);

        $data = $request->validate(['reference' => ['required', 'string', 'max:500']]);
        $payment->forceFill(['customer_note' => $data['reference']])->save();

        return back()->with('success', 'Thanks! We will verify your payment and activate the plan.');
    }

    private function authorizePayment(Request $request, Payment $payment): void
    {
        abort_unless($payment->user_id === $request->user()->id, 404);
    }
}
