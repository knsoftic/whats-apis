<x-app-layout :title="$account->name">
    <x-page-header :title="$account->name" :description="($account->display_phone_number ?? 'Phone number not confirmed').($account->verified_name ? ' · '.$account->verified_name : '')">
        <x-button variant="secondary" icon="arrow-left" :href="route('accounts.index')">All accounts</x-button>
        <x-button variant="secondary" icon="pencil" :href="route('accounts.edit', $account)">Edit</x-button>
        <x-button icon="activity" :href="route('accounts.manage', $account)">WhatsApp Manager</x-button>
    </x-page-header>

    {{-- Health overview --}}
    <div class="grid gap-4 lg:grid-cols-3">
        @php
            $cards = [
                [
                    'title' => 'API connection',
                    'ok' => $account->isApiConnected(),
                    'bad' => $account->api_status === 'failed',
                    'text' => match ($account->api_status) { 'connected' => 'Token & IDs confirmed by Meta', 'failed' => 'Meta rejected the credentials', default => 'Not checked yet' },
                    'when' => $account->api_checked_at,
                    'detail' => $account->api_status_message,
                ],
                [
                    'title' => 'Webhook verification',
                    'ok' => $account->isWebhookVerified(),
                    'bad' => false,
                    'text' => $account->isWebhookVerified() ? 'Meta verified the callback URL' : 'Waiting for Meta to verify the callback URL',
                    'when' => $account->webhook_verified_at,
                    'detail' => $account->last_webhook_at ? 'Last event received '.$account->last_webhook_at->diffForHumans().'.' : 'No events received yet.',
                ],
                [
                    'title' => 'App subscribed to WABA',
                    'ok' => $account->isSubscribed(),
                    'bad' => $account->subscription_status === 'failed',
                    'text' => match ($account->subscription_status) { 'subscribed' => 'Webhooks will be delivered', 'not_subscribed' => 'No app subscribed — webhooks will not arrive', 'failed' => 'Could not check', default => 'Unknown — run Test connection' },
                    'when' => $account->subscription_checked_at,
                    'detail' => $account->subscription_status_message,
                ],
            ];
        @endphp
        @foreach ($cards as $c)
            <div @class([
                'rounded-2xl border p-5',
                'border-brand-200 bg-brand-50/60 dark:border-brand-900 dark:bg-brand-500/5' => $c['ok'],
                'border-rose-200 bg-rose-50/60 dark:border-rose-900 dark:bg-rose-500/5' => ! $c['ok'] && $c['bad'],
                'border-amber-200 bg-amber-50/60 dark:border-amber-900 dark:bg-amber-500/5' => ! $c['ok'] && ! $c['bad'],
            ])>
                <div class="flex items-center gap-2">
                    <span @class(['h-2.5 w-2.5 rounded-full', 'bg-brand-500' => $c['ok'], 'bg-rose-500' => ! $c['ok'] && $c['bad'], 'bg-amber-500' => ! $c['ok'] && ! $c['bad']])></span>
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $c['title'] }}</h3>
                </div>
                <p class="mt-2 text-sm text-slate-700 dark:text-slate-300">{{ $c['text'] }}</p>
                @if ($c['detail'])
                    <p class="mt-1 break-words text-xs text-slate-500 dark:text-slate-400">{{ $c['detail'] }}</p>
                @endif
                <p class="mt-2 text-xs text-slate-400">{{ $c['when'] ? 'Updated '.$c['when']->diffForHumans() : '' }}</p>
            </div>
        @endforeach
    </div>

    @if ($account->isEmbeddedSignup() || $account->registration_status)
        <div @class(['mt-4 flex flex-wrap items-center gap-3 rounded-2xl border px-5 py-4',
            'border-brand-200 bg-brand-50/60 dark:border-brand-900 dark:bg-brand-500/5' => in_array($account->registration_status, ['registered', 'coexistence', 'not_required'], true),
            'border-rose-200 bg-rose-50/60 dark:border-rose-900 dark:bg-rose-500/5' => $account->registration_status === 'failed',
            'border-slate-200 dark:border-slate-800' => ! $account->registration_status])>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-slate-900 dark:text-white">
                    <x-badge color="blue">Embedded Signup</x-badge>
                    <span class="ml-2">Cloud API registration:
                        {{ match ($account->registration_status) { 'registered' => 'registered', 'coexistence' => 'WhatsApp Business app (coexistence)', 'failed' => 'not registered', default => 'unknown' } }}</span>
                </p>
                @if ($account->registration_message)
                    <p class="mt-1 text-xs text-slate-600 dark:text-slate-400">{{ $account->registration_message }}</p>
                @endif
            </div>
            @if (! in_array($account->registration_status, ['registered', 'coexistence'], true))
                <form method="POST" action="{{ route('accounts.register', $account) }}" class="flex items-end gap-2">
                    @csrf
                    <div>
                        <label class="form-label" for="reg_pin">6-digit PIN</label>
                        <input id="reg_pin" name="pin" class="form-input w-32 font-mono tracking-widest" inputmode="numeric" maxlength="6" pattern="\d{6}" required>
                    </div>
                    <x-button>Register number</x-button>
                </form>
                @error('pin')<p class="form-error w-full">{{ $message }}</p>@enderror
            @endif
        </div>
    @endif

    <div class="mt-4 flex flex-wrap gap-2">
        <form method="POST" action="{{ route('accounts.test', $account) }}" x-data="{ busy: false }" @submit="busy = true">
            @csrf
            <x-button ::disabled="busy"><span x-show="busy" class="spinner" x-cloak></span><x-icon name="refresh" class="h-4 w-4" x-show="!busy" /> Test connection</x-button>
        </form>
        <form method="POST" action="{{ route('accounts.subscribe', $account) }}" x-data="{ busy: false }" @submit="busy = true">
            @csrf
            <x-button variant="secondary" ::disabled="busy"><span x-show="busy" class="spinner" x-cloak></span><x-icon name="link" class="h-4 w-4" x-show="!busy" /> Subscribe app to WABA</x-button>
        </form>
        <form method="POST" action="{{ route('accounts.toggle', $account) }}">
            @csrf @method('PATCH')
            <x-button variant="secondary" icon="power">{{ $account->is_active ? 'Disable account' : 'Enable account' }}</x-button>
        </form>
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-2">
        {{-- Webhook setup --}}
        @if ($account->isEmbeddedSignup())
        <x-card title="Webhook (shared platform URL)" subtitle="Numbers connected with Embedded Signup use your Tech Provider app's single webhook.">
            <div class="space-y-3 text-sm">
                <p>Events for this number arrive at <code class="break-all rounded bg-slate-100 px-1.5 py-0.5 text-xs dark:bg-slate-800">{{ route('webhooks.whatsapp.platform') }}</code>, configured once in Settings → Meta Tech Provider.</p>
                <p class="text-slate-500">Nothing to set up per number. If messages don't arrive: check that the platform webhook is verified and click “Subscribe app to WABA” above.</p>
                @can(\App\Support\Permissions::SETTINGS_MANAGE)
                    <x-button size="sm" variant="secondary" icon="key" :href="route('settings.meta')">Platform webhook settings</x-button>
                @endcan
            </div>
        </x-card>
        @else
        <x-card title="Webhook setup in Meta" subtitle="App Dashboard → WhatsApp → Configuration → Webhook → Edit">
            <div class="space-y-4 text-sm">
                <div>
                    <p class="form-label">Callback URL</p>
                    <div class="flex gap-2" x-data="copyText(@js($account->webhookUrl()))">
                        <input readonly value="{{ $account->webhookUrl() }}" class="form-input font-mono text-xs" @focus="$el.select()">
                        <x-button type="button" variant="secondary" x-on:click="copy()"><span x-text="copied ? 'Copied' : 'Copy'"></span></x-button>
                    </div>
                    @if (! str_starts_with($account->webhookUrl(), 'https://'))
                        <p class="form-help text-amber-600 dark:text-amber-400">Meta only accepts a public <strong>HTTPS</strong> URL. Use your VPS domain, or a tunnel (ngrok / cloudflared) during local testing and set APP_URL accordingly.</p>
                    @endif
                </div>
                <div x-data="{ token: null, loading: false, copied: false,
                        async reveal() { this.loading = true; try { this.token = (await http(@js(route('accounts.verify-token', $account)))).verify_token } catch (e) { toast(e.message, 'error') } this.loading = false },
                        async copy() { await navigator.clipboard.writeText(this.token); this.copied = true; setTimeout(() => this.copied = false, 1500) } }">
                    <p class="form-label">Verify token</p>
                    <div class="flex gap-2">
                        <input readonly :value="token ?? '••••••••••••••••••••'" class="form-input font-mono text-xs">
                        <x-button type="button" variant="secondary" x-show="!token" x-on:click="reveal()" ::disabled="loading">Reveal</x-button>
                        <x-button type="button" variant="secondary" x-show="token" x-cloak x-on:click="copy()"><span x-text="copied ? 'Copied' : 'Copy'"></span></x-button>
                    </div>
                </div>
                <div>
                    <p class="form-label">Webhook fields to subscribe</p>
                    <p><code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs dark:bg-slate-800">messages</code> <span class="text-slate-500">— required (incoming messages + delivery statuses)</span></p>
                    <p class="mt-1 text-xs text-slate-500">Optional: <code>message_template_status_update</code>, <code>phone_number_quality_update</code>, <code>account_update</code>.</p>
                </div>
                <ol class="list-decimal space-y-1 pl-5 text-slate-600 dark:text-slate-400">
                    <li>Paste the Callback URL and Verify token in Meta, click <em>Verify and save</em>.</li>
                    <li>Subscribe the <strong>messages</strong> field.</li>
                    <li>Click <em>Subscribe app to WABA</em> above (or it is done automatically by Meta's embedded signup).</li>
                    <li>Send a WhatsApp message to this number — it should appear in Chats within seconds.</li>
                </ol>
            </div>
        </x-card>
        @endif

        {{-- Credentials --}}
        <x-card title="Credentials & identifiers" subtitle="Secrets are encrypted at rest and masked here.">
            <dl class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                @foreach ([
                    'WABA ID' => $account->waba_id,
                    'Phone Number ID' => $account->phone_number_id,
                    'Meta Business ID' => $account->meta_business_id ?? '—',
                    'Graph API version' => $account->graph_api_version,
                    'Access token' => $account->maskedAccessToken(),
                    'App Secret' => $account->maskedAppSecret(),
                ] as $label => $value)
                    <div class="flex justify-between gap-4 py-2.5">
                        <dt class="text-slate-500">{{ $label }}</dt>
                        <dd class="truncate text-right font-mono text-xs text-slate-800 dark:text-slate-200">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-card>

        {{-- Phone details --}}
        <x-card title="Phone number details from Meta">
            @php $meta = $account->phone_meta ?? []; @endphp
            <dl class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                @foreach ([
                    'Display number' => $account->display_phone_number,
                    'Verified name' => $account->verified_name,
                    'Quality rating' => $account->quality_rating,
                    'Code verification' => $meta['code_verification_status'] ?? null,
                    'Display name status' => $meta['name_status'] ?? null,
                    'Platform' => $meta['platform_type'] ?? null,
                    'Throughput' => $meta['throughput']['level'] ?? null,
                ] as $label => $value)
                    <div class="flex justify-between gap-4 py-2.5">
                        <dt class="text-slate-500">{{ $label }}</dt>
                        <dd class="text-right font-medium">
                            @if ($label === 'Quality rating' && $value)
                                <x-badge :color="match (strtoupper($value)) { 'GREEN' => 'green', 'YELLOW' => 'amber', 'RED' => 'red', default => 'slate' }">{{ $value }}</x-badge>
                            @else
                                {{ $value ?? '—' }}
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>
        </x-card>

        {{-- Usage + recent events --}}
        <x-card title="Usage" :padding="false">
            <div class="grid grid-cols-2 gap-3 p-5 sm:grid-cols-4">
                @foreach (['Contacts' => $account->contacts_count, 'Conversations' => $account->conversations_count, 'Messages' => $account->messages_count, 'Templates' => $account->templates_count] as $label => $n)
                    <div class="rounded-xl bg-slate-50 px-3 py-2 dark:bg-slate-800/60">
                        <p class="text-xs text-slate-500">{{ $label }}</p>
                        <p class="text-lg font-semibold">{{ number_format($n) }}</p>
                    </div>
                @endforeach
            </div>
            <div class="border-t border-slate-100 px-5 py-3 text-sm font-semibold dark:border-slate-800">Recent webhook events</div>
            @forelse ($recentEvents as $event)
                <div class="flex items-center justify-between gap-3 border-t border-slate-100 px-5 py-2 text-xs dark:border-slate-800">
                    <span class="font-mono text-slate-500">#{{ $event->id }}</span>
                    <span>{{ $event->event_type ?? '—' }}</span>
                    <x-badge :color="match ($event->status) { 'processed' => 'green', 'failed' => 'red', default => 'amber' }">{{ $event->status }}</x-badge>
                    <span class="text-slate-400">{{ $event->created_at->diffForHumans(short: true) }}</span>
                </div>
            @empty
                <p class="border-t border-slate-100 px-5 py-4 text-sm text-slate-500 dark:border-slate-800">No webhook events received yet.</p>
            @endforelse
        </x-card>
    </div>

    {{-- Danger zone (staff only — customers keep the numbers they connected) --}}
    @can('delete', $account)
        <x-card class="mt-6 border-rose-200 dark:border-rose-900" title="Danger zone">
            @if ($account->owner)
                <p class="mb-3 text-sm text-slate-600 dark:text-slate-400">Owned by customer <strong>{{ $account->owner->name }}</strong> ({{ $account->owner->email }}). Deleting it frees one number slot on their plan.</p>
            @endif
            <form method="POST" action="{{ route('accounts.destroy', $account) }}" class="flex flex-wrap items-end gap-3"
                  onsubmit="return confirm('Delete this WhatsApp account and ALL its contacts, conversations, messages and templates? This cannot be undone.')">
                @csrf @method('DELETE')
                <div class="min-w-[240px] flex-1">
                    <label class="form-label" for="confirm_name">Type <strong>{{ $account->name }}</strong> to delete this account and all of its data</label>
                    <input id="confirm_name" name="confirm_name" class="form-input" autocomplete="off">
                    @error('confirm_name')<p class="form-error">{{ $message }}</p>@enderror
                </div>
                <x-button variant="danger" icon="trash">Delete account</x-button>
            </form>
            <p class="mt-3 text-xs text-slate-500">Tip: use “Disable account” instead if you only want to stop sending.</p>
        </x-card>
    @else
        <x-card class="mt-6">
            <p class="flex items-start gap-3 text-sm text-slate-600 dark:text-slate-400">
                <x-icon name="lock" class="mt-0.5 h-5 w-5 shrink-0 text-slate-400" />
                <span>This number is linked to your account and can’t be removed or swapped here. To remove it, please contact support{{ $brand->get('site.support_email') ? ' ('.$brand->get('site.support_email').')' : '' }}. You can still disable it to stop sending.</span>
            </p>
        </x-card>
    @endcan
</x-app-layout>
