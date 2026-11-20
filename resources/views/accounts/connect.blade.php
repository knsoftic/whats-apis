<x-app-layout title="Connect with Meta">
    <div class="mx-auto max-w-3xl">
        <x-page-header title="Connect a WhatsApp number" description="Use Meta's Embedded Signup — the customer logs in with Facebook, picks or creates a WhatsApp Business Account and verifies the number. No IDs or tokens to copy.">
            <x-button variant="secondary" icon="arrow-left" :href="route('accounts.index')">Back</x-button>
        </x-page-header>

        @if (! $platform->isConfigured())
            <x-card>
                <x-empty-state icon="key" title="Embedded Signup is not set up yet"
                               description="A Super Admin must enter your Meta Tech Provider App ID, App Secret and Embedded Signup Configuration ID first.">
                    <div class="flex flex-wrap justify-center gap-2">
                        @can(\App\Support\Permissions::SETTINGS_MANAGE)
                            <x-button icon="key" :href="route('settings.meta')">Open Meta Tech Provider settings</x-button>
                        @endcan
                        <x-button variant="secondary" icon="pencil" :href="route('accounts.create')">Add manually instead</x-button>
                    </div>
                </x-empty-state>
            </x-card>
        @else
            <div x-data="embeddedSignup(@js(['appId' => $platform->appId(), 'configId' => $platform->configId(), 'version' => $platform->graphVersion(), 'url' => route('accounts.embedded-signup'), 'pin' => $suggestedPin]))">
                <x-card>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <label class="form-label" for="es_name">Account name (optional)</label>
                            <input id="es_name" x-model="name" class="form-input" maxlength="120" placeholder="e.g. Sales – India">
                            <p class="form-help">Empty = the verified WhatsApp business name.</p>
                        </div>
                        <div>
                            <label class="form-label" for="es_pin">Two-step verification PIN</label>
                            <input id="es_pin" x-model="pin" class="form-input font-mono tracking-widest" inputmode="numeric" maxlength="6" pattern="\d{6}">
                            <p class="form-help">6 digits used to register the number on Cloud API — <strong>save it</strong>. If the number already has a PIN, enter that one.</p>
                        </div>
                    </div>

                    <div class="mt-6 flex flex-col items-center gap-3 rounded-2xl bg-slate-50 px-6 py-8 text-center dark:bg-slate-800/50">
                        <button type="button" @click="launch()" :disabled="!sdkReady || busy"
                                class="inline-flex items-center gap-3 rounded-xl bg-[#1877F2] px-6 py-3 text-sm font-semibold text-white shadow hover:bg-[#166fe0] disabled:cursor-not-allowed disabled:opacity-60">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M24 12.07C24 5.41 18.63 0 12 0S0 5.4 0 12.07C0 18.1 4.39 23.1 10.13 24v-8.44H7.08v-3.49h3.04V9.41c0-3.02 1.8-4.7 4.54-4.7 1.31 0 2.68.24 2.68.24v2.97h-1.5c-1.5 0-1.96.93-1.96 1.89v2.26h3.32l-.53 3.5h-2.8V24C19.62 23.1 24 18.1 24 12.07"/></svg>
                            <span x-show="!busy">Connect WhatsApp with Facebook</span>
                            <span x-show="busy" x-cloak class="flex items-center gap-2"><span class="spinner"></span> Working…</span>
                        </button>
                        <p x-show="!sdkReady && !error" class="text-xs text-slate-500">Loading Facebook…</p>

                        <ol class="mt-2 flex flex-wrap justify-center gap-4 text-xs">
                            <template x-for="(label, i) in ['Meta popup', 'Finishing setup', 'Done']" :key="i">
                                <li class="flex items-center gap-1.5" :class="step > i ? 'text-brand-600 dark:text-brand-400 font-semibold' : 'text-slate-400'">
                                    <span class="flex h-5 w-5 items-center justify-center rounded-full border text-[10px]" :class="step > i ? 'border-brand-500 bg-brand-500 text-white' : 'border-slate-300'" x-text="i + 1"></span>
                                    <span x-text="label"></span>
                                </li>
                            </template>
                        </ol>

                        <p x-show="error" x-cloak class="w-full rounded-xl bg-rose-50 px-4 py-3 text-left text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-300" x-text="error"></p>
                    </div>

                    <div class="mt-6 text-sm text-slate-600 dark:text-slate-400">
                        <p class="font-medium text-slate-800 dark:text-slate-200">What happens</p>
                        <ol class="mt-2 list-decimal space-y-1 pl-5">
                            <li>A Meta window opens: log in, choose/create the business portfolio and WhatsApp Business Account, add and verify the phone number.</li>
                            <li>This app exchanges Meta's one-time code for a business token (server-side), subscribes webhooks and registers the number with your PIN.</li>
                            <li>The number appears under WhatsApp Accounts and messages start arriving through the platform webhook.</li>
                        </ol>
                        <p class="mt-3 text-xs">After connecting, the business adds a payment method in WhatsApp Manager to send template (business-initiated) messages.</p>
                    </div>
                </x-card>

                <p class="mt-4 text-center text-sm text-slate-500">
                    Already have the IDs and a permanent token? <a href="{{ route('accounts.create') }}" class="font-medium text-brand-600 hover:underline dark:text-brand-400">Add the number manually</a>.
                </p>
            </div>
        @endif
    </div>
</x-app-layout>
