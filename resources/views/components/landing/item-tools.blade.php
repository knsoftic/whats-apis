@props(['section'])
{{-- Move up / down / delete buttons for one repeatable row (uses `i` from the x-for scope). --}}
<div class="flex shrink-0 items-center gap-0.5">
    <span class="mr-1 text-xs font-semibold text-slate-400" x-text="'#' + (i + 1)"></span>
    <button type="button" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700 disabled:opacity-30 dark:hover:bg-slate-800 dark:hover:text-slate-200" x-on:click="move('{{ $section }}', i, -1)" x-bind:disabled="i === 0" aria-label="Move up">
        <x-icon name="chevron-down" class="h-4 w-4 rotate-180" />
    </button>
    <button type="button" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700 disabled:opacity-30 dark:hover:bg-slate-800 dark:hover:text-slate-200" x-on:click="move('{{ $section }}', i, 1)" x-bind:disabled="i === c.{{ $section }}.items.length - 1" aria-label="Move down">
        <x-icon name="chevron-down" class="h-4 w-4" />
    </button>
    <button type="button" class="rounded-lg p-1.5 text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-500/10" x-on:click="remove('{{ $section }}', i)" aria-label="Remove">
        <x-icon name="trash" class="h-4 w-4" />
    </button>
</div>
