<x-app-layout title="Plans">
    <x-page-header title="Plans" description="What each customer may use: Connect with Meta, manual numbers, limits and bot flows. Admin-owned numbers have no limits.">
        <x-button icon="plus" :href="route('plans.create')">New plan</x-button>
    </x-page-header>

    <x-card class="mb-6">
        <form method="POST" action="{{ route('plans.settings') }}" class="flex flex-wrap items-center justify-between gap-4">
            @csrf
            <div>
                <p class="font-semibold text-slate-900 dark:text-white">Public registration</p>
                <p class="text-sm text-slate-500">Anyone can create a customer account at <code>{{ route('register') }}</code> and gets the default plan.</p>
            </div>
            <div class="flex items-center gap-3">
                <x-badge :color="$registrationEnabled ? 'green' : 'slate'" dot>{{ $registrationEnabled ? 'ON' : 'OFF' }}</x-badge>
                <input type="hidden" name="registration_enabled" value="{{ $registrationEnabled ? 0 : 1 }}">
                <x-button variant="secondary">{{ $registrationEnabled ? 'Turn off' : 'Turn on' }}</x-button>
            </div>
        </form>
    </x-card>

    @if ($plans->isEmpty())
        <x-card><x-empty-state icon="chart" title="No plans yet" description="Create a plan and mark one as default for new registrations." /></x-card>
    @else
        <div class="grid gap-5 md:grid-cols-2 2xl:grid-cols-3">
            @foreach ($plans as $plan)
                <x-card class="flex flex-col">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h3 class="text-lg font-semibold text-slate-900 dark:text-white">{{ $plan->name }}</h3>
                            <p class="text-2xl font-bold text-emerald-600 dark:text-emerald-400">{{ $plan->priceLabel() }}</p>
                        </div>
                        <div class="flex flex-col items-end gap-1">
                            @if ($plan->is_default)<x-badge color="blue">Default</x-badge>@endif
                            <x-badge :color="$plan->is_active ? 'green' : 'slate'" dot>{{ $plan->is_active ? 'Active' : 'Inactive' }}</x-badge>
                        </div>
                    </div>
                    @if ($plan->description)<p class="mt-2 text-sm text-slate-500">{{ $plan->description }}</p>@endif

                    <ul class="mt-4 space-y-1.5 text-sm">
                        @foreach ([
                            ['Connect with Meta (Embedded Signup)', $plan->allow_embedded_signup],
                            ['Add numbers manually', $plan->allow_manual],
                            ['Bot flows'.($plan->allow_bot_flows && $plan->max_bot_flows !== null ? ' (max '.$plan->max_bot_flows.')' : ''), $plan->allow_bot_flows],
                        ] as [$label, $on])
                            <li class="flex items-center gap-2 {{ $on ? '' : 'text-slate-400 line-through' }}">
                                <x-icon :name="$on ? 'check' : 'x'" class="h-4 w-4 {{ $on ? 'text-emerald-600' : 'text-slate-400' }}" />{{ $label }}
                            </li>
                        @endforeach
                    </ul>

                    <dl class="mt-4 grid grid-cols-3 gap-2 text-center text-xs">
                        <div class="rounded-xl bg-slate-50 p-2 dark:bg-slate-800/60"><dt class="text-slate-500">Numbers</dt><dd class="font-semibold">{{ \App\Models\Plan::limitLabel($plan->max_whatsapp_accounts) }}</dd></div>
                        <div class="rounded-xl bg-slate-50 p-2 dark:bg-slate-800/60"><dt class="text-slate-500">Msgs / day</dt><dd class="font-semibold">{{ \App\Models\Plan::limitLabel($plan->daily_message_limit) }}</dd></div>
                        <div class="rounded-xl bg-slate-50 p-2 dark:bg-slate-800/60"><dt class="text-slate-500">Contacts</dt><dd class="font-semibold">{{ \App\Models\Plan::limitLabel($plan->max_contacts) }}</dd></div>
                    </dl>

                    <div class="mt-auto flex items-center justify-between pt-5 text-xs text-slate-500">
                        <span>{{ $plan->users_count }} user(s){{ $plan->trial_days ? ' · '.$plan->trial_days.'-day trial' : '' }}</span>
                        <div class="flex gap-1">
                            <x-button size="sm" variant="ghost" icon="pencil" :href="route('plans.edit', $plan)">Edit</x-button>
                            @if ($plan->users_count === 0)
                                <form method="POST" action="{{ route('plans.destroy', $plan) }}" onsubmit="return confirm('Delete plan {{ addslashes($plan->name) }}?')">
                                    @csrf @method('DELETE')
                                    <x-button size="sm" variant="ghost" icon="trash" class="text-rose-600">Delete</x-button>
                                </form>
                            @endif
                        </div>
                    </div>
                </x-card>
            @endforeach
        </div>
    @endif
</x-app-layout>
