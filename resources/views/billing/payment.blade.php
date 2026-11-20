<x-app-layout title="Payment">
    <div class="mx-auto max-w-2xl"
         @if ($payment->isPending() && $payment->gateway === 'pkpay')
             x-data="{ async poll() { try { const r = await http(@js(route('billing.status', $payment))); if (r.status !== 'pending') location.reload(); } catch (e) {} } }"
             x-init="setInterval(() => poll(), 5000)"
         @endif>
        <x-page-header :title="'Payment '.$payment->reference" :description="$payment->plan_name.' · '.$payment->gatewayLabel()">
            <x-button variant="secondary" icon="arrow-left" :href="route('billing.index')">Billing</x-button>
        </x-page-header>

        <x-card>
            <div class="flex flex-col items-center py-6 text-center">
                @if ($payment->isPaid())
                    <span class="flex h-14 w-14 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15"><x-icon name="check" class="h-8 w-8" /></span>
                    <h2 class="mt-4 text-xl font-semibold">Payment received</h2>
                    <p class="mt-1 text-sm text-slate-500">Your <strong>{{ $payment->plan_name }}</strong> plan is active. Thank you!</p>
                    <x-button class="mt-5" :href="route('dashboard')">Go to dashboard</x-button>
                @elseif ($payment->isPending())
                    <span class="spinner h-10 w-10 border-4 text-brand-600"></span>
                    <h2 class="mt-4 text-xl font-semibold">{{ $payment->gateway === 'manual' ? 'Awaiting your payment' : 'Waiting for payment confirmation' }}</h2>
                    <p class="mt-1 text-sm text-slate-500">Amount: <strong>{{ $payment->amountLabel() }}</strong></p>
                    @if ($payment->gateway === 'pkpay')
                        <p class="mt-2 text-sm text-slate-500">This page updates automatically. If you closed the payment page, open it again:</p>
                        @if ($payment->checkout_url && (! $payment->expires_at || $payment->expires_at->isFuture()))
                            <x-button class="mt-4" :href="$payment->checkout_url">Open payment page</x-button>
                        @else
                            <p class="mt-3 text-sm text-amber-600">The QR code has expired. If you already paid, wait a moment; otherwise start a new payment.</p>
                            <x-button class="mt-4" variant="secondary" :href="route('billing.checkout', $payment->plan_id)">Start a new payment</x-button>
                        @endif
                    @endif
                @else
                    <span class="flex h-14 w-14 items-center justify-center rounded-full bg-rose-100 text-rose-600 dark:bg-rose-500/15"><x-icon name="x" class="h-8 w-8" /></span>
                    <h2 class="mt-4 text-xl font-semibold">Payment {{ $payment->status }}</h2>
                    @if ($payment->admin_note)<p class="mt-1 text-sm text-slate-500">{{ $payment->admin_note }}</p>@endif
                    @if ($payment->plan_id)
                        <x-button class="mt-5" :href="route('billing.checkout', $payment->plan_id)">Try again</x-button>
                    @endif
                @endif
            </div>
        </x-card>

        @if ($payment->gateway === 'manual' && $payment->isPending())
            <x-card class="mt-6" title="How to pay">
                <div class="whitespace-pre-line rounded-xl bg-slate-50 p-4 text-sm dark:bg-slate-800/60">{{ $instructions ?: 'Payment instructions have not been set yet. Please contact support.' }}</div>
                <p class="mt-3 text-sm">Use <strong class="font-mono">{{ $payment->reference }}</strong> as the payment reference / remarks.</p>
                <form method="POST" action="{{ route('billing.manual', $payment) }}" class="mt-4 space-y-2">
                    @csrf
                    <label class="form-label" for="reference">Transaction ID / sender details</label>
                    <textarea id="reference" name="reference" rows="2" class="form-input" required maxlength="500" placeholder="e.g. TID 1234567890 from JazzCash 0300…">{{ old('reference', $payment->customer_note) }}</textarea>
                    @error('reference')<p class="form-error">{{ $message }}</p>@enderror
                    <div class="flex justify-end"><x-button>{{ $payment->customer_note ? 'Update details' : 'I have paid' }}</x-button></div>
                </form>
                @if ($payment->customer_note)
                    <p class="mt-2 text-xs text-emerald-600">Submitted — an admin will verify and activate your plan.</p>
                @endif
            </x-card>
        @endif
    </div>
</x-app-layout>
