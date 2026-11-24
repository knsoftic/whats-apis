{{--
    Sidebar "Chats" item with live inbox counters (Alpine component chatCounts).
    $counts = ['all','unread','messages','waiting','open','closed'] across every number the user can open.
--}}
@php
    $fmt = fn ($n) => $n > 999 ? '999+' : (string) $n;
    $stats = [
        ['key' => 'messages', 'label' => 'Unread msgs', 'filter' => 'unread', 'tone' => 'text-brand-700 dark:text-brand-300', 'title' => 'Unread customer messages'],
        ['key' => 'waiting', 'label' => 'Waiting reply', 'filter' => 'waiting', 'tone' => 'text-amber-600 dark:text-amber-400', 'title' => 'Open chats where the customer wrote last'],
        ['key' => 'open', 'label' => 'Open chats', 'filter' => 'open', 'tone' => 'text-slate-700 dark:text-slate-200', 'title' => 'Open chats'],
        ['key' => 'all', 'label' => 'All chats', 'filter' => null, 'tone' => 'text-slate-700 dark:text-slate-200', 'title' => 'All chats'],
    ];
@endphp
<div x-data="chatCounts(@js($counts), @js(route('chat.unread-summary')))" data-chat-counts>
    <a href="{{ route($item['route']) }}" @class([
        'group flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition',
        'bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300' => $active,
        'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white' => ! $active,
    ])>
        <x-icon :name="$item['icon']" class="h-5 w-5 shrink-0" />
        <span class="truncate">{{ $item['label'] }}</span>
        <span x-show="c.unread > 0" @style(['display: none' => ! $counts['unread']])
              class="ml-auto min-w-[1.25rem] rounded-full bg-brand-600 px-1.5 py-0.5 text-center text-[10px] font-bold leading-none text-white"
              :title="c.unread + ' unread chat(s)'" title="{{ $counts['unread'] }} unread chat(s)"
              x-text="c.unread > 99 ? '99+' : c.unread">{{ $counts['unread'] > 99 ? '99+' : $counts['unread'] }}</span>
    </a>

    {{-- Mini counters under "Chats" (each opens the inbox with that filter) --}}
    <div x-show="c.all > 0" @style(['display: none' => ! $counts['all']]) class="mb-1 ml-11 mr-1 mt-1 grid grid-cols-2 gap-1">
        @foreach ($stats as $s)
            <a href="{{ route('chat.index', $s['filter'] ? ['filter' => $s['filter']] : []) }}" title="{{ $s['title'] }}"
               class="rounded-lg bg-slate-50 px-2 py-1 leading-tight transition hover:bg-slate-100 dark:bg-slate-800/60 dark:hover:bg-slate-800">
                <span class="block text-xs font-bold tabular-nums {{ $s['tone'] }}" x-text="fmt(c.{{ $s['key'] }})" data-count="{{ $s['key'] }}">{{ $fmt($counts[$s['key']]) }}</span>
                <span class="block truncate text-[10px] text-slate-500 dark:text-slate-400">{{ $s['label'] }}</span>
            </a>
        @endforeach
    </div>
</div>
