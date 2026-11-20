<x-app-layout title="Meta Tech Provider">
    <div class="mx-auto max-w-4xl">
        @include('settings._tabs')
        <x-page-header title="Meta Tech Provider settings" description="Your own Meta app, used for WhatsApp Embedded Signup (“Connect with Meta”) and the shared webhook for all numbers onboarded through it." />

        <div class="mb-6 grid gap-4 sm:grid-cols-3">
            @foreach ([
                ['Embedded Signup', $platform->isConfigured(), $platform->isConfigured() ? 'Ready — “Connect with Meta” is enabled' : 'App ID, App Secret and Configuration ID needed'],
                ['Platform webhook', (bool) $platform->webhookVerifiedAt(), $platform->webhookVerifiedAt() ? 'Verified by Meta '.$platform->webhookVerifiedAt()->diffForHumans() : 'Not verified yet — step 2 below'],
                ['Numbers onboarded', $embeddedAccounts > 0, $embeddedAccounts.' via Embedded Signup'],
            ] as [$title, $okState, $text])
                <div @class(['rounded-2xl border p-4',
                    'border-brand-200 bg-brand-50/60 dark:border-brand-900 dark:bg-brand-500/5' => $okState,
                    'border-amber-200 bg-amber-50/60 dark:border-amber-900 dark:bg-amber-500/5' => ! $okState])>
                    <p class="flex items-center gap-2 text-sm font-semibold"><span @class(['h-2.5 w-2.5 rounded-full', 'bg-brand-500' => $okState, 'bg-amber-500' => ! $okState])></span>{{ $title }}</p>
                    <p class="mt-1 text-xs text-slate-600 dark:text-slate-400">{{ $text }}</p>
                </div>
            @endforeach
        </div>

        <form method="POST" action="{{ route('settings.meta.update') }}" x-data="{ saving: false, showSecret: false, showToken: false, token: @js(old('webhook_verify_token', $platform->verifyToken() ?? $suggestedVerifyToken)) }" @submit="saving = true" autocomplete="off">
            @csrf @method('PUT')

            <x-card title="1. Meta app (Tech Provider)" subtitle="developers.facebook.com → your app → App settings → Basic, and Facebook Login for Business → Configurations.">
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label class="form-label" for="app_id">App ID</label>
                        <input id="app_id" name="app_id" class="form-input font-mono" inputmode="numeric" value="{{ old('app_id', $platform->appId()) }}" required>
                        @error('app_id')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="form-label" for="app_secret">App Secret</label>
                        @if ($platform->appSecret())
                            <p class="mb-1 text-xs text-slate-500">Current: <code class="font-mono">{{ $appSecretMasked }}</code> — leave blank to keep.</p>
                        @endif
                        <div class="relative">
                            <input id="app_secret" name="app_secret" :type="showSecret ? 'text' : 'password'" class="form-input pr-16 font-mono" autocomplete="new-password" @required(! $platform->appSecret())>
                            <button type="button" @click="showSecret = !showSecret" class="absolute right-2 top-1/2 -translate-y-1/2 px-2 text-xs text-slate-500" x-text="showSecret ? 'Hide' : 'Show'"></button>
                        </div>
                        @error('app_secret')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="form-label" for="es_config_id">Embedded Signup Configuration ID</label>
                        <input id="es_config_id" name="es_config_id" class="form-input font-mono" inputmode="numeric" value="{{ old('es_config_id', $platform->configId()) }}" required>
                        <p class="form-help">Facebook Login for Business → Configurations → “WhatsApp Embedded Signup” configuration.</p>
                        @error('es_config_id')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="form-label" for="graph_version">Graph API version</label>
                        <input id="graph_version" name="graph_version" class="form-input font-mono" value="{{ old('graph_version', $platform->graphVersion()) }}" required>
                        @error('graph_version')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                </div>
            </x-card>

            <x-card class="mt-6" title="2. Platform webhook" subtitle="Set this ONCE in your Meta app → WhatsApp → Configuration. Every number onboarded with Embedded Signup sends its events here.">
                <div class="space-y-4">
                    <div>
                        <p class="form-label">Callback URL</p>
                        <div class="flex gap-2" x-data="copyText(@js($platform->webhookUrl()))">
                            <input readonly value="{{ $platform->webhookUrl() }}" class="form-input font-mono text-xs" @focus="$el.select()">
                            <x-button type="button" variant="secondary" x-on:click="copy()"><span x-text="copied ? 'Copied' : 'Copy'"></span></x-button>
                        </div>
                    </div>
                    <div>
                        <label class="form-label" for="webhook_verify_token">Verify token</label>
                        <div class="flex gap-2">
                            <input id="webhook_verify_token" name="webhook_verify_token" x-model="token" :type="showToken ? 'text' : 'password'" class="form-input font-mono" required>
                            <button type="button" class="shrink-0 rounded-xl border border-slate-300 px-3 text-xs dark:border-slate-700" @click="showToken = !showToken" x-text="showToken ? 'Hide' : 'Show'"></button>
                            <button type="button" class="shrink-0 rounded-xl border border-slate-300 px-3 text-xs dark:border-slate-700" @click="token = Array.from(crypto.getRandomValues(new Uint8Array(20)), b => b.toString(16).padStart(2, '0')).join(''); showToken = true">Generate</button>
                        </div>
                        @error('webhook_verify_token')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                    <p class="text-xs text-slate-500">Subscribe the webhook fields <code>messages</code>, <code>account_update</code> and (optional) <code>message_template_status_update</code>, <code>phone_number_quality_update</code>.</p>
                </div>
            </x-card>

            <x-card class="mt-6" title="3. Facebook Login for Business settings (in Meta)">
                <ul class="list-disc space-y-1 pl-5 text-sm text-slate-600 dark:text-slate-400">
                    <li>Client OAuth login, Web OAuth login, Enforce HTTPS, Embedded Browser OAuth Login, Strict mode, <strong>Login with the JavaScript SDK</strong> → Yes</li>
                    <li>Allowed domains for the JavaScript SDK and Valid OAuth Redirect URIs → <code>{{ rtrim(config('app.url'), '/') }}</code></li>
                    <li>Configuration: template “WhatsApp Embedded Signup Configuration…” (or custom with the <em>WhatsApp Embedded Signup</em> login variation), permissions <code>whatsapp_business_management</code> + <code>whatsapp_business_messaging</code></li>
                </ul>
            </x-card>

            <div class="mt-6 flex flex-wrap justify-end gap-2">
                <x-button ::disabled="saving"><span x-show="saving" class="spinner" x-cloak></span>Save settings</x-button>
            </div>
        </form>

        <form method="POST" action="{{ route('settings.meta.test') }}" class="mt-3 flex justify-end">
            @csrf
            <x-button variant="secondary" icon="refresh">Test App ID + App Secret</x-button>
        </form>
    </div>
</x-app-layout>
