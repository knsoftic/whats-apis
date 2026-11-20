@php $editing = $account->exists; @endphp
<x-app-layout :title="$editing ? 'Edit WhatsApp account' : 'Add WhatsApp account'">
    <div class="mx-auto max-w-4xl">
        <x-page-header :title="$editing ? 'Edit '.$account->name : 'Add WhatsApp account'"
                       description="Copy these values from developers.facebook.com → your app → WhatsApp → API Setup, and App Settings → Basic.">
            <x-button variant="secondary" icon="arrow-left" :href="$editing ? route('accounts.show', $account) : route('accounts.index')">Back</x-button>
        </x-page-header>

        <form method="POST" action="{{ $editing ? route('accounts.update', $account) : route('accounts.store') }}"
              x-data="{ saving: false, verifyToken: @js(old('webhook_verify_token', $editing ? '' : $suggestedVerifyToken)), showToken: false, showSecret: false }"
              @submit="saving = true" autocomplete="off">
            @csrf
            @if ($editing) @method('PUT') @endif

            <div class="space-y-6">
                <x-card title="1. Account" subtitle="A name your team will recognise, e.g. “Sales – India”.">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label class="form-label" for="name">Account name</label>
                            <input id="name" name="name" class="form-input" value="{{ old('name', $account->name) }}" required maxlength="120">
                            @error('name')<p class="form-error">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="form-label" for="meta_business_id">Meta Business Account ID <span class="font-normal text-slate-400">(optional)</span></label>
                            <input id="meta_business_id" name="meta_business_id" class="form-input font-mono" inputmode="numeric" value="{{ old('meta_business_id', $account->meta_business_id) }}">
                            <p class="form-help">Business Settings → Business info. Stored as metadata only.</p>
                            @error('meta_business_id')<p class="form-error">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="form-label" for="graph_api_version">Meta Graph API version</label>
                            <input id="graph_api_version" name="graph_api_version" class="form-input font-mono" value="{{ old('graph_api_version', $account->graph_api_version) }}" required placeholder="v26.0">
                            <p class="form-help">Default {{ config('whatsapp.default_graph_version') }}. Change only if Meta asks you to.</p>
                            @error('graph_api_version')<p class="form-error">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </x-card>

                <x-card title="2. WhatsApp identifiers" subtitle="App Dashboard → WhatsApp → API Setup.">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <label class="form-label" for="waba_id">WhatsApp Business Account ID (WABA ID)</label>
                            <input id="waba_id" name="waba_id" class="form-input font-mono" inputmode="numeric" value="{{ old('waba_id', $account->waba_id) }}" required>
                            @error('waba_id')<p class="form-error">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="form-label" for="phone_number_id">Phone Number ID</label>
                            <input id="phone_number_id" name="phone_number_id" class="form-input font-mono" inputmode="numeric" value="{{ old('phone_number_id', $account->phone_number_id) }}" required>
                            <p class="form-help">The numeric ID shown under the number — not the phone number itself.</p>
                            @error('phone_number_id')<p class="form-error">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </x-card>

                <x-card title="3. Secrets" subtitle="Encrypted before saving. The full access token is never shown again.">
                    <div class="grid gap-5">
                        <div>
                            <label class="form-label" for="access_token">WhatsApp access token</label>
                            @if ($editing)
                                <p class="mb-2 flex items-center gap-2 text-xs text-slate-500"><x-icon name="lock" class="h-4 w-4" /> Current: <code class="font-mono">{{ $account->maskedAccessToken() }}</code> — leave blank to keep it.</p>
                            @endif
                            <textarea id="access_token" name="access_token" rows="3" class="form-input font-mono text-xs" spellcheck="false" @required(! $editing)
                                      placeholder="EAAG… (use a permanent System User token for production)"></textarea>
                            @error('access_token')<p class="form-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="grid gap-5 sm:grid-cols-2">
                            <div>
                                <label class="form-label" for="app_secret">App Secret</label>
                                @if ($editing)
                                    <p class="mb-2 text-xs text-slate-500">Current: <code class="font-mono">{{ $account->maskedAppSecret() }}</code> — leave blank to keep it.</p>
                                @endif
                                <div class="relative">
                                    <input id="app_secret" name="app_secret" :type="showSecret ? 'text' : 'password'" class="form-input pr-16 font-mono" @required(! $editing) autocomplete="new-password" spellcheck="false">
                                    <button type="button" @click="showSecret = !showSecret" class="absolute right-2 top-1/2 -translate-y-1/2 rounded px-2 text-xs text-slate-500 hover:text-slate-800" x-text="showSecret ? 'Hide' : 'Show'"></button>
                                </div>
                                <p class="form-help">App Settings → Basic → App Secret. Used to verify webhook signatures.</p>
                                @error('app_secret')<p class="form-error">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="form-label" for="webhook_verify_token">Webhook verify token</label>
                                @if ($editing)
                                    <p class="mb-2 text-xs text-slate-500">Leave blank to keep the current token.</p>
                                @endif
                                <div class="flex gap-2">
                                    <input id="webhook_verify_token" name="webhook_verify_token" x-model="verifyToken" :type="showToken ? 'text' : 'password'" class="form-input font-mono" @required(! $editing) spellcheck="false">
                                    <button type="button" class="shrink-0 rounded-xl border border-slate-300 px-3 text-xs dark:border-slate-700" @click="showToken = !showToken" x-text="showToken ? 'Hide' : 'Show'"></button>
                                    <button type="button" class="shrink-0 rounded-xl border border-slate-300 px-3 text-xs dark:border-slate-700"
                                            @click="verifyToken = Array.from(crypto.getRandomValues(new Uint8Array(20)), b => b.toString(16).padStart(2, '0')).join(''); showToken = true">Generate</button>
                                </div>
                                <p class="form-help">Any random string you choose. Paste the same value into Meta → WhatsApp → Configuration → Verify token.</p>
                                @error('webhook_verify_token')<p class="form-error">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    </div>
                </x-card>

                @if (($owners ?? collect())->isNotEmpty())
                    <x-card title="Owner (customer)" subtitle="The customer whose plan limits apply to this number. Empty = platform-owned, no limits.">
                        <select name="owner_id" class="form-input">
                            <option value="">— Platform (no plan limits) —</option>
                            @foreach ($owners as $owner)
                                <option value="{{ $owner->id }}" @selected((int) old('owner_id', $account->owner_id) === $owner->id)>{{ $owner->name }}{{ $owner->company_name ? ' · '.$owner->company_name : '' }} ({{ $owner->email }})</option>
                            @endforeach
                        </select>
                        @error('owner_id')<p class="form-error">{{ $message }}</p>@enderror
                    </x-card>
                @endif

                <x-card>
                    <label class="flex items-center gap-3 text-sm">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800" @checked(old('is_active', $account->is_active ?? true))>
                        <span><span class="font-medium">Enabled</span> — disabled accounts cannot send messages.</span>
                    </label>
                </x-card>
            </div>

            <div class="mt-6 flex flex-wrap items-center justify-end gap-3">
                <p class="mr-auto flex items-center gap-2 text-xs text-slate-500"><x-icon name="info" class="h-4 w-4" /> After saving, the app checks your credentials with Meta automatically.</p>
                <x-button variant="secondary" :href="$editing ? route('accounts.show', $account) : route('accounts.index')">Cancel</x-button>
                <x-button ::disabled="saving"><span x-show="saving" class="spinner" x-cloak></span>{{ $editing ? 'Save & re-check' : 'Save & validate' }}</x-button>
            </div>
        </form>
    </div>
</x-app-layout>
