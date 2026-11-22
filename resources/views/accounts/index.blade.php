<x-app-layout title="WhatsApp Accounts">
    <x-page-header title="WhatsApp Accounts" description="Official WhatsApp Business Cloud API numbers — connect with Meta (Embedded Signup) or add credentials manually.">
        @if ($canConnect)<x-button :href="route('accounts.connect')" icon="link">Connect with Meta</x-button>@endif
        @if ($canManual)<x-button variant="secondary" :href="route('accounts.create')" icon="plus">Add manually</x-button>@endif
    </x-page-header>

    @if ($numberLimit && $numberLimit['max'] !== null)
        <div @class(['mb-6 flex flex-wrap items-center gap-3 rounded-2xl border px-5 py-4 text-sm',
            'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-500/10 dark:text-amber-200' => $numberLimit['full'],
            'border-slate-200 bg-white text-slate-700 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300' => ! $numberLimit['full']])>
            <x-icon :name="$numberLimit['full'] ? 'lock' : 'phone'" class="h-5 w-5 shrink-0" />
            <p class="min-w-0 flex-1">
                <strong>{{ $numberLimit['used'] }} / {{ $numberLimit['max'] }}</strong> WhatsApp numbers used on your {{ $numberLimit['plan'] }} plan.
                @if ($numberLimit['full'])
                    You can't add more. Connected numbers stay linked to your account — upgrade your plan to add another, or contact support to remove one.
                @else
                    Numbers you connect stay linked to your account and can only be removed by support.
                @endif
            </p>
            @if ($numberLimit['full'] && Route::has('billing.index'))
                <x-button size="sm" :href="route('billing.index')" icon="chart">Upgrade plan</x-button>
            @endif
        </div>
    @elseif (! ($canConnect || $canManual))
        <div class="mb-6 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
            Your plan does not allow adding WhatsApp numbers right now (no active plan or feature not included). Please contact the administrator.
        </div>
    @endif

    @if ($accounts->isEmpty())
        <div class="grid gap-5 md:grid-cols-2">
            <x-card>
                <div class="flex h-full flex-col">
                    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-[#1877F2]/10 text-[#1877F2]"><x-icon name="link" class="h-6 w-6" /></span>
                    <h3 class="mt-3 font-semibold text-slate-900 dark:text-white">Connect with Meta <span class="ml-1 rounded bg-brand-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-brand-700 dark:bg-brand-500/15 dark:text-brand-300">Recommended</span></h3>
                    <p class="mt-1 flex-1 text-sm text-slate-500">Embedded Signup: log in with Facebook, choose the WhatsApp Business Account and verify the number. Tokens, webhooks and registration are handled automatically.</p>
                    <x-button class="mt-4 self-start" :href="route('accounts.connect')" icon="link">Connect with Meta</x-button>
                </div>
            </x-card>
            <x-card>
                <div class="flex h-full flex-col">
                    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-slate-100 text-slate-500 dark:bg-slate-800"><x-icon name="pencil" class="h-6 w-6" /></span>
                    <h3 class="mt-3 font-semibold text-slate-900 dark:text-white">Add manually</h3>
                    <p class="mt-1 flex-1 text-sm text-slate-500">Enter WABA ID, Phone Number ID, a permanent access token and App Secret from your own Meta app. Nothing counts as connected until Meta confirms API access and the webhook.</p>
                    <x-button variant="secondary" class="mt-4 self-start" :href="route('accounts.create')" icon="plus">Add manually</x-button>
                </div>
            </x-card>
        </div>
    @else
        <div class="grid gap-5 md:grid-cols-2 2xl:grid-cols-3">
            @foreach ($accounts as $account)
                <x-card class="flex flex-col">
                    <div class="flex items-start gap-4">
                        <span @class([
                            'flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl',
                            'bg-brand-100 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400' => $account->isOperational(),
                            'bg-amber-100 text-amber-600 dark:bg-amber-500/15 dark:text-amber-400' => $account->is_active && ! $account->isOperational(),
                            'bg-slate-100 text-slate-400 dark:bg-slate-800' => ! $account->is_active,
                        ])>
                            <x-icon name="phone" class="h-6 w-6" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('accounts.show', $account) }}" class="block truncate text-base font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-400">{{ $account->name }}</a>
                            <p class="truncate text-sm text-slate-500 dark:text-slate-400">
                                {{ $account->display_phone_number ?? 'Phone number not confirmed yet' }}
                                @if ($account->verified_name) · {{ $account->verified_name }} @endif
                            </p>
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-1.5">
                        <x-badge :color="$account->isEmbeddedSignup() ? 'blue' : 'slate'">{{ $account->isEmbeddedSignup() ? 'Embedded Signup' : 'Manual' }}</x-badge>
                        @if ($account->registration_status === 'failed')<x-badge color="red" dot>Not registered</x-badge>@endif
                    </div>
                    <div class="mt-2">@include('accounts.partials.status-badges', ['account' => $account])</div>

                    <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                        <div class="rounded-xl bg-slate-50 px-3 py-2 dark:bg-slate-800/60">
                            <dt class="text-xs text-slate-500">Conversations</dt>
                            <dd class="font-semibold">{{ number_format($account->conversations_count) }}</dd>
                        </div>
                        <div class="rounded-xl bg-slate-50 px-3 py-2 dark:bg-slate-800/60">
                            <dt class="text-xs text-slate-500">Unread</dt>
                            <dd class="font-semibold">{{ number_format($account->unread_conversations_count) }}</dd>
                        </div>
                        <div class="col-span-2 rounded-xl bg-slate-50 px-3 py-2 text-xs dark:bg-slate-800/60">
                            <span class="text-slate-500">Last webhook:</span>
                            <span class="font-medium">{{ $account->last_webhook_at?->diffForHumans() ?? 'never' }}</span>
                            <span class="text-slate-400">·</span>
                            <span class="text-slate-500">API checked:</span>
                            <span class="font-medium">{{ $account->api_checked_at?->diffForHumans() ?? 'never' }}</span>
                        </div>
                    </dl>

                    @if ($account->api_status === 'failed' && $account->api_status_message)
                        <p class="mt-3 rounded-xl bg-rose-50 px-3 py-2 text-xs text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">{{ \Illuminate\Support\Str::limit($account->api_status_message, 220) }}</p>
                    @endif

                    <div class="mt-auto flex flex-wrap gap-2 pt-5">
                        <x-button size="sm" variant="secondary" icon="eye" :href="route('accounts.show', $account)">Details</x-button>
                        <form method="POST" action="{{ route('accounts.test', $account) }}" x-data="{ busy: false }" @submit="busy = true">
                            @csrf
                            <x-button size="sm" variant="secondary" ::disabled="busy">
                                <span x-show="busy" class="spinner" x-cloak></span>
                                <x-icon name="refresh" class="h-4 w-4" x-show="!busy" />
                                Test connection
                            </x-button>
                        </form>
                        <x-button size="sm" variant="ghost" icon="pencil" :href="route('accounts.edit', $account)">Edit</x-button>
                    </div>
                </x-card>
            @endforeach
        </div>
    @endif
</x-app-layout>
