<x-app-layout title="Billing & Plan">
    <x-page-header title="Billing & Plan" description="Your current plan, usage and upgrades." />

    @php $current = $user->plan; @endphp
    <x-card>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-sm text-slate-500">Current plan</p>
                <p class="text-2xl font-semibold text-slate-900 dark:text-white">{{ $current?->name ?? 'No plan' }}</p>
                @if ($user->plan_expires_at)
                    <p @class(['text-sm', 'text-rose-600' => $user->plan_expires_at->isPast(), 'text-slate-500' => ! $user->plan_expires_at->isPast()])>
                        {{ $user->plan_expires_at->isPast() ? 'Expired on' : 'Valid until' }} {{ $user->plan_expires_at->toFormattedDateString() }}
                    </p>
                @elseif ($current)
                    <p class="text-sm text-slate-500">No expiry</p>
                @endif
            </div>
            @if ($usage)
                <div class="grid grid-cols-2 gap-x-8 gap-y-1 text-sm">
                    <span class="text-slate-500">Messages today</span><span class="font-medium">{{ number_format($usage['messages_today']) }} / {{ $usage['daily_limit'] === null ? '∞' : number_format($usage['daily_limit']) }}</span>
                    <span class="text-slate-500">WhatsApp numbers</span><span class="font-medium">{{ $usage['accounts'] }} / {{ $usage['max_accounts'] ?? '∞' }}</span>
                    <span class="text-slate-500">Contacts</span><span class="font-medium">{{ number_format($usage['contacts']) }} / {{ $usage['max_contacts'] === null ? '∞' : number_format($usage['max_contacts']) }}</span>
                </div>
            @endif
        </div>
    </x-card>

    <h2 class="mb-4 mt-8 text-lg font-semibold text-slate-900 dark:text-white">Plans</h2>
    @unless ($selfPurchase)
        <p class="mb-4 rounded-xl bg-slate-100 px-4 py-3 text-sm text-slate-600 dark:bg-slate-800 dark:text-slate-300">Online purchase is not enabled yet — contact {{ $brand->get('site.support_email') ?: 'the administrator' }} to change your plan.</p>
    @endunless
    <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($plans as $plan)
            @php $isCurrent = $current?->id === $plan->id; @endphp
            <x-card class="flex flex-col {{ $isCurrent ? 'ring-2 ring-brand-500' : '' }}">
                <div class="flex items-start justify-between">
                    <h3 class="text-lg font-semibold">{{ $plan->name }}</h3>
                    @if ($isCurrent)<x-badge color="green">Current</x-badge>@endif
                </div>
                <p class="mt-1 text-2xl font-bold text-brand-600 dark:text-brand-400">{{ $plan->priceLabel() }}</p>
                @if ($plan->description)<p class="mt-2 text-sm text-slate-500">{{ $plan->description }}</p>@endif
                <ul class="mt-4 space-y-1.5 text-sm">
                    <li>{{ \App\Models\Plan::limitLabel($plan->max_whatsapp_accounts) }} WhatsApp number(s)</li>
                    <li>{{ \App\Models\Plan::limitLabel($plan->daily_message_limit) }} messages / day</li>
                    <li>{{ \App\Models\Plan::limitLabel($plan->max_contacts) }} contacts</li>
                    <li @class(['text-slate-400 line-through' => ! $plan->allow_embedded_signup])>Connect with Meta</li>
                    <li @class(['text-slate-400 line-through' => ! $plan->allow_bot_flows])>Bot flows{{ $plan->allow_bot_flows && $plan->max_bot_flows !== null ? ' ('.$plan->max_bot_flows.')' : '' }}</li>
                </ul>
                <div class="mt-auto pt-5">
                    @if ((float) $plan->price <= 0)
                        @unless ($isCurrent)
                            <form method="POST" action="{{ route('billing.free', $plan) }}" onsubmit="return confirm('Switch to the free {{ addslashes($plan->name) }} plan?')">
                                @csrf
                                <x-button variant="secondary" class="w-full">Switch to {{ $plan->name }}</x-button>
                            </form>
                        @endunless
                    @elseif ($selfPurchase)
                        <x-button class="w-full" :href="route('billing.checkout', $plan)">{{ $isCurrent ? 'Renew' : 'Buy '.$plan->name }}</x-button>
                    @endif
                </div>
            </x-card>
        @endforeach
    </div>

    <x-card class="mt-8" title="Payment history" :padding="false">
        @forelse ($history as $p)
            <a href="{{ route('billing.payment', $p) }}" class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-5 py-3 text-sm last:border-0 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800/50">
                <span class="font-mono text-xs text-slate-500">{{ $p->reference }}</span>
                <span class="flex-1">{{ $p->plan_name }} · {{ $p->gatewayLabel() }}</span>
                <span class="font-medium">{{ $p->amountLabel() }}</span>
                <x-badge :color="$p->statusColor()">{{ $p->status }}</x-badge>
                <span class="text-xs text-slate-400">{{ $p->created_at->toFormattedDateString() }}</span>
            </a>
        @empty
            <x-empty-state icon="inbox" title="No payments yet" />
        @endforelse
    </x-card>
</x-app-layout>
