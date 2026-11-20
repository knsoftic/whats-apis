<x-app-layout title="Checkout">
    <div class="mx-auto max-w-2xl">
        <x-page-header :title="'Buy '.$plan->name" description="Choose how you want to pay.">
            <x-button variant="secondary" icon="arrow-left" :href="route('billing.index')">Plans</x-button>
        </x-page-header>

        <x-card>
            <div class="flex items-center justify-between">
                <div>
                    <p class="font-semibold">{{ $plan->name }}</p>
                    <p class="text-sm text-slate-500">{{ \App\Models\Plan::PERIODS[$plan->billing_period] ?? $plan->billing_period }}</p>
                </div>
                <p class="text-2xl font-bold text-brand-600 dark:text-brand-400">{{ $plan->priceLabel() }}</p>
            </div>
        </x-card>

        @if (empty($methods))
            <x-card class="mt-6"><x-empty-state icon="warning" title="No payment method available" description="{{ strtoupper($plan->currency) !== 'PKR' ? 'Online payment (PKPay) works for PKR prices only.' : 'Please contact the administrator.' }}" /></x-card>
        @else
            <form method="POST" action="{{ route('billing.start', $plan) }}" class="mt-6" x-data="{ busy: false }" @submit="busy = true">
                @csrf
                <x-card title="Payment method">
                    <div class="space-y-3">
                        @foreach ($methods as $value => $label)
                            <label class="flex cursor-pointer items-center gap-3 rounded-2xl border border-slate-200 p-4 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">
                                <input type="radio" name="method" value="{{ $value }}" class="text-brand-600 focus:ring-brand-500" @checked($loop->first)>
                                <span class="text-sm font-medium">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    <p class="form-help mt-3">PKPay opens a secure payment page with a Raast QR code (valid 5 minutes). Your plan activates automatically after payment.</p>
                </x-card>
                <div class="mt-6 flex justify-end">
                    <x-button ::disabled="busy"><span x-show="busy" class="spinner" x-cloak></span>Continue to payment</x-button>
                </div>
            </form>
        @endif
    </div>
</x-app-layout>
