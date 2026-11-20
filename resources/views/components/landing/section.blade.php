@props(['key', 'title', 'description' => null, 'icon' => 'template', 'toggle' => true])
{{-- One collapsible block of the landing page editor (state lives in landingEditor()). --}}
<section id="{{ $key }}" class="scroll-mt-24 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
    <div class="flex items-center gap-3 px-4 py-3.5 sm:px-5">
        <button type="button" x-on:click="toggle('{{ $key }}')" class="flex min-w-0 flex-1 items-center gap-3 text-left">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400"><x-icon :name="$icon" class="h-5 w-5" /></span>
            <span class="min-w-0">
                <span class="block font-semibold text-slate-900 dark:text-white">{{ $title }}</span>
                @if ($description)<span class="block truncate text-xs text-slate-500 dark:text-slate-400">{{ $description }}</span>@endif
            </span>
        </button>
        @if ($toggle)
            <label class="flex shrink-0 cursor-pointer items-center gap-2 text-xs font-medium text-slate-500 dark:text-slate-400">
                <span class="hidden sm:inline" x-text="c.{{ $key }}.enabled ? 'Shown' : 'Hidden'"></span>
                <span class="relative inline-flex">
                    <input type="checkbox" class="peer sr-only" x-model="c.{{ $key }}.enabled" aria-label="Show {{ $title }} on the page">
                    <span class="h-6 w-11 rounded-full bg-slate-300 transition peer-checked:bg-brand-600 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500 dark:bg-slate-700"></span>
                    <span class="pointer-events-none absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span>
                </span>
            </label>
        @endif
        <button type="button" x-on:click="toggle('{{ $key }}')" class="shrink-0 rounded-lg p-1 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800" aria-label="Open {{ $title }}">
            <x-icon name="chevron-down" class="h-5 w-5 transition" x-bind:class="open === '{{ $key }}' && 'rotate-180'" />
        </button>
    </div>
    <div x-show="open === '{{ $key }}'" x-cloak class="space-y-5 border-t border-slate-100 px-4 py-5 dark:border-slate-800 sm:px-5">
        {{ $slot }}
    </div>
</section>
