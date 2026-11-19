@props(['label', 'value', 'icon' => 'chart', 'color' => 'emerald', 'hint' => null, 'href' => null])
@php
    $tones = [
        'emerald' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400',
        'sky' => 'bg-sky-50 text-sky-600 dark:bg-sky-500/10 dark:text-sky-400',
        'violet' => 'bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-400',
        'amber' => 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400',
        'rose' => 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400',
        'slate' => 'bg-slate-100 text-slate-600 dark:bg-slate-500/10 dark:text-slate-300',
    ];
    $tag = $href ? 'a' : 'div';
@endphp
<{{ $tag }} @if ($href) href="{{ $href }}" @endif {{ $attributes->merge(['class' => 'group flex items-start gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition dark:border-slate-800 dark:bg-slate-900'.($href ? ' hover:border-emerald-300 hover:shadow-md dark:hover:border-emerald-700' : '')]) }}>
    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl {{ $tones[$color] ?? $tones['emerald'] }}">
        <x-icon :name="$icon" class="h-6 w-6" />
    </span>
    <div class="min-w-0">
        <p class="text-sm font-medium text-slate-500 dark:text-slate-400">{{ $label }}</p>
        <p class="mt-1 text-2xl font-semibold tracking-tight text-slate-900 dark:text-white">{{ $value }}</p>
        @if ($hint)
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $hint }}</p>
        @endif
    </div>
</{{ $tag }}>
