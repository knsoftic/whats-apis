<x-app-layout title="Settings">
    <div class="mx-auto max-w-4xl">
        @include('settings._tabs')

        <form method="POST" action="{{ route('settings.update', 'payments') }}" x-data="{ saving: false, showSecret: false }" @submit="saving = true" autocomplete="off">
            @csrf @method('PUT')

            <x-card title="Billing">
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label class="form-label" for="currency">Default currency</label>
                        <select id="currency" name="currency" class="form-input">
                            @foreach (\App\Support\PlatformSettings::CURRENCIES as $code => $label)
                                <option value="{{ $code }}" @selected(old('currency', $s->currency()) === $code)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="form-help">Used for new plans and prices. PKPay accepts PKR only.</p>
                    </div>
                    <label class="flex items-center gap-3 self-end pb-2 text-sm">
                        <input type="hidden" name="self_purchase" value="0">
                        <input type="checkbox" name="self_purchase" value="1" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800" @checked($s->bool('billing.self_purchase'))>
                        <span><span class="font-medium">Self-purchase</span> — customers can buy / upgrade plans on the Billing page</span>
                    </label>
                </div>
            </x-card>

            <x-card class="mt-6" title="PKPay (Raast QR)" subtitle="Online payment. The plan activates automatically when PKPay's signed callback confirms the payment.">
                <label class="flex items-center gap-3 text-sm">
                    <input type="hidden" name="pkpay_enabled" value="0">
                    <input type="checkbox" name="pkpay_enabled" value="1" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800" @checked($s->bool('billing.pkpay_enabled'))>
                    <span class="font-medium">Enable PKPay</span>
                </label>
                <div class="mt-4 grid gap-5 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="form-label" for="pkpay_base_url">API base URL</label>
                        <input id="pkpay_base_url" name="pkpay_base_url" class="form-input font-mono text-sm" value="{{ old('pkpay_base_url', $s->get('billing.pkpay_base_url')) }}" required>
                        @error('pkpay_base_url')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="form-label" for="pkpay_merchant_id">Merchant ID</label>
                        <input id="pkpay_merchant_id" name="pkpay_merchant_id" class="form-input font-mono" value="{{ old('pkpay_merchant_id', $s->get('billing.pkpay_merchant_id')) }}">
                    </div>
                    <div>
                        <label class="form-label" for="pkpay_api_key">API key (pk_…)</label>
                        @if ($s->get('billing.pkpay_api_key'))<p class="mb-1 text-xs text-slate-500">Current: <code>{{ $masked('billing.pkpay_api_key') }}</code> — blank keeps it.</p>@endif
                        <input id="pkpay_api_key" name="pkpay_api_key" class="form-input font-mono" autocomplete="off" spellcheck="false">
                        @error('pkpay_api_key')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label class="form-label" for="pkpay_api_secret">API secret (sk_…)</label>
                        @if ($s->get('billing.pkpay_api_secret'))<p class="mb-1 text-xs text-slate-500">Current: <code>{{ $masked('billing.pkpay_api_secret') }}</code> — blank keeps it.</p>@endif
                        <div class="relative">
                            <input id="pkpay_api_secret" name="pkpay_api_secret" :type="showSecret ? 'text' : 'password'" class="form-input pr-16 font-mono" autocomplete="new-password" spellcheck="false">
                            <button type="button" @click="showSecret = !showSecret" class="absolute right-2 top-1/2 -translate-y-1/2 px-2 text-xs text-slate-500" x-text="showSecret ? 'Hide' : 'Show'"></button>
                        </div>
                        @error('pkpay_api_secret')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="mt-5 space-y-2 rounded-xl bg-slate-50 p-4 text-sm dark:bg-slate-800/60">
                    <p><span class="font-medium">Callback URL</span> (sent automatically with each payment): <code class="break-all">{{ route('payments.pkpay.callback') }}</code></p>
                    <p><span class="font-medium">Server IP</span> must be on PKPay's pay-in IP allowlist (ask the PKPay admin) — otherwise requests fail with <code>ip_not_allowed</code>.</p>
                    <p class="text-xs text-slate-500">Raast QR expires after 5 minutes; pending payments are re-checked automatically every few minutes. Sandbox callbacks are never used to activate plans.</p>
                </div>
            </x-card>

            <x-card class="mt-6" title="Manual payment (bank transfer / wallet)" subtitle="The customer pays outside the site and submits a reference; an admin approves it on the Payments page.">
                <label class="flex items-center gap-3 text-sm">
                    <input type="hidden" name="manual_enabled" value="0">
                    <input type="checkbox" name="manual_enabled" value="1" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800" @checked($s->bool('billing.manual_enabled'))>
                    <span class="font-medium">Enable manual payment</span>
                </label>
                <div class="mt-4">
                    <label class="form-label" for="manual_instructions">Payment instructions shown to customers</label>
                    <textarea id="manual_instructions" name="manual_instructions" rows="5" class="form-input" maxlength="3000" placeholder="Bank: Meezan Bank&#10;Account title: KN Softic&#10;IBAN: PK00…&#10;JazzCash / Easypaisa: 03xx…&#10;After paying, enter the transaction ID below.">{{ old('manual_instructions', $s->get('billing.manual_instructions')) }}</textarea>
                </div>
            </x-card>

            <div class="mt-6 flex justify-end"><x-button ::disabled="saving"><span x-show="saving" class="spinner" x-cloak></span>Save payment settings</x-button></div>
        </form>
    </div>
</x-app-layout>
