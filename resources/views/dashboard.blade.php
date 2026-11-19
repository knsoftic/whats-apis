<x-app-layout title="Dashboard">
    <x-page-header title="Dashboard" :description="$currentAccount ? 'Showing: '.$currentAccount->label() : 'Showing all WhatsApp numbers you can access'">
        @if (Route::has('chat.index'))
            @can(\App\Support\Permissions::CHATS_VIEW)
                <x-button :href="route('chat.index')" icon="chat">Open chats</x-button>
            @endcan
        @endif
    </x-page-header>

    {{-- Stat cards --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-card label="Connected WhatsApp numbers" :value="$stats['numbers_operational'].' / '.$stats['numbers_total']" icon="phone" color="emerald"
                     :hint="$stats['numbers_api_ok'].' with working API access'" />
        <x-stat-card label="Total contacts" :value="number_format($stats['contacts'])" icon="contacts" color="sky" />
        <x-stat-card label="Messages sent" :value="number_format($stats['sent'])" icon="arrow-up-right" color="violet" />
        <x-stat-card label="Messages received" :value="number_format($stats['received'])" icon="arrow-down-left" color="emerald" />
        <x-stat-card label="Failed messages" :value="number_format($stats['failed'])" icon="x-circle" color="rose" />
        <x-stat-card label="Unread conversations" :value="number_format($stats['unread'])" icon="envelope" color="amber" />
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-3">
        {{-- Recent conversations --}}
        <x-card title="Recent conversations" class="xl:col-span-2" :padding="false">
            @forelse ($recent as $conversation)
                <a href="{{ Route::has('chat.index') ? route('chat.index', ['conversation' => $conversation->id]) : '#' }}"
                   class="flex items-center gap-4 border-b border-slate-100 px-5 py-3 last:border-0 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800/50">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-sm font-semibold text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">
                        {{ $conversation->contact->initials() }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <p class="truncate font-medium text-slate-900 dark:text-white">{{ $conversation->contact->displayName() }}</p>
                            <span class="hidden truncate text-xs text-slate-400 sm:inline">· {{ $conversation->account->name }}</span>
                        </div>
                        <p class="truncate text-sm text-slate-500 dark:text-slate-400">
                            @if ($conversation->last_message_direction === 'outbound')<span class="text-slate-400">You: </span>@endif{{ $conversation->last_message_preview }}
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-1">
                        <span class="text-xs text-slate-400">{{ $conversation->last_message_at?->diffForHumans(short: true) }}</span>
                        @if ($conversation->unread_count > 0)
                            <span class="min-w-[20px] rounded-full bg-emerald-500 px-1.5 py-0.5 text-center text-[11px] font-semibold text-white">{{ $conversation->unread_count }}</span>
                        @endif
                    </div>
                </a>
            @empty
                <x-empty-state icon="chat" title="No conversations yet" description="When customers message your WhatsApp numbers, the latest conversations appear here." />
            @endforelse
        </x-card>

        <div class="space-y-6">
            {{-- 7-day activity --}}
            <x-card title="Last 7 days" subtitle="Messages sent vs received">
                <div class="flex h-40 items-end gap-2" role="img" aria-label="Messages per day for the last 7 days">
                    @foreach ($chart as $day)
                        <div class="flex flex-1 flex-col items-center gap-1">
                            <div class="flex h-32 w-full items-end justify-center gap-0.5">
                                <div class="w-1/2 rounded-t bg-emerald-500" style="height: {{ round($day['in'] / $chartMax * 100) }}%" title="{{ $day['in'] }} received on {{ $day['date'] }}"></div>
                                <div class="w-1/2 rounded-t bg-violet-500" style="height: {{ round($day['out'] / $chartMax * 100) }}%" title="{{ $day['out'] }} sent on {{ $day['date'] }}"></div>
                            </div>
                            <span class="text-[11px] text-slate-400">{{ $day['label'] }}</span>
                        </div>
                    @endforeach
                </div>
                <div class="mt-3 flex gap-4 text-xs text-slate-500 dark:text-slate-400">
                    <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-emerald-500"></span>Received</span>
                    <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-violet-500"></span>Sent</span>
                </div>
            </x-card>

            {{-- Number health --}}
            <x-card title="WhatsApp numbers" :padding="false">
                @forelse ($accounts->take(6) as $account)
                    <div class="flex items-center gap-3 border-b border-slate-100 px-5 py-3 last:border-0 dark:border-slate-800">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-slate-900 dark:text-white">{{ $account->name }}</p>
                            <p class="truncate text-xs text-slate-500">{{ $account->display_phone_number ?? 'Phone ID '.$account->phone_number_id }}</p>
                        </div>
                        @include('accounts.partials.status-badges', ['account' => $account, 'compact' => true])
                    </div>
                @empty
                    <x-empty-state icon="phone" title="No WhatsApp numbers" description="Add your first official WhatsApp Cloud API number to get started.">
                        @if (Route::has('accounts.create'))
                            @can(\App\Support\Permissions::ACCOUNTS_MANAGE)
                                <x-button :href="route('accounts.create')" icon="plus" size="sm">Add WhatsApp account</x-button>
                            @endcan
                        @endif
                    </x-empty-state>
                @endforelse
            </x-card>
        </div>
    </div>
</x-app-layout>
