<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Support\Permissions as P;
use App\Services\Payments\PaymentGatewayException;
use App\Services\Payments\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Payments (Super Admin): all plan payments, approve / reject manual ones,
 * re-check PKPay status.
 */
class PaymentAdminController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:pending,paid,failed,expired,cancelled,rejected'],
            'gateway' => ['nullable', 'in:pkpay,manual,free'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $payments = Payment::with(['user:id,name,email,company_name', 'plan:id,name'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['gateway'] ?? null, fn ($q, $g) => $q->where('gateway', $g))
            ->when($filters['q'] ?? null, fn ($q, $t) => $q->where(fn ($w) => $w->where('reference', 'like', "%{$t}%")
                ->orWhere('gateway_transaction_id', 'like', "%{$t}%")
                ->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%{$t}%")->orWhere('name', 'like', "%{$t}%"))))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('payments.index', [
            'payments' => $payments,
            'filters' => $filters,
            'totals' => [
                'paid' => Payment::where('status', Payment::PAID)->selectRaw('currency, SUM(amount) as total')->groupBy('currency')->pluck('total', 'currency'),
                'awaiting' => Payment::where('status', Payment::PENDING)->where('gateway', Payment::GATEWAY_MANUAL)->whereNotNull('customer_note')->count(),
            ],
        ]);
    }

    public function approve(Request $request, Payment $payment): RedirectResponse
    {
        abort_unless($payment->isPending(), 422);

        $this->payments->markPaid($payment, now(), $request->user());

        return back()->with('success', "Payment {$payment->reference} approved — plan activated.");
    }

    public function reject(Request $request, Payment $payment): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $this->payments->reject($payment, $request->user(), $data['note'] ?? null);

        return back()->with('success', "Payment {$payment->reference} rejected.");
    }

    public function sync(Payment $payment): RedirectResponse
    {
        try {
            $payment = $this->payments->syncPkPay($payment);
        } catch (PaymentGatewayException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "PKPay status: {$payment->status}.");
    }

    /** Super Admin only: set the status by hand (e.g. an expired payment that was actually received). */
    public function changeStatus(Request $request, Payment $payment): RedirectResponse
    {
        abort_unless($request->user()->hasRole(P::ROLE_SUPER_ADMIN), 403, 'Only the Super Admin can change a payment status.');

        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', PaymentService::ADMIN_STATUSES)],
            'note' => ['nullable', 'string', 'max:300'],
            'activate_plan' => ['nullable', 'boolean'],
        ]);

        if ($data['status'] === $payment->status) {
            return back()->with('info', "Payment {$payment->reference} is already {$payment->status}.");
        }

        $from = $payment->status;
        $activated = $this->payments->changeStatus($payment, $data['status'], $request->user(), $data['note'] ?? null, $request->boolean('activate_plan'));

        $message = "Payment {$payment->reference}: {$from} → {$data['status']}.";

        if ($activated) {
            $message .= " Plan {$payment->plan_name} activated for ".($payment->user?->email ?? 'the customer').'.';
        } elseif ($data['status'] === Payment::PAID && $request->boolean('activate_plan')) {
            $message .= ' The plan was already activated for this payment before, so it was not extended again.';
        } elseif ($from === Payment::PAID) {
            $message .= " The customer's plan was not changed — edit it on the Users page if needed.";
        }

        return back()->with('success', $message);
    }
}
