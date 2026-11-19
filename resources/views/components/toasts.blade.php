{{-- Toast notifications. Server flash messages + window.dispatchEvent(new CustomEvent('toast', {detail:{type, message}})) --}}
@php
    $flash = collect([
        ['type' => 'success', 'message' => session('success')],
        ['type' => 'error', 'message' => session('error')],
        ['type' => 'warning', 'message' => session('warning')],
        ['type' => 'info', 'message' => session('info')],
    ])->filter(fn ($t) => filled($t['message']))->values();
@endphp
<div x-data="toasts(@js($flash))" @toast.window="add($event.detail)"
     class="pointer-events-none fixed inset-x-0 top-4 z-[60] flex flex-col items-center gap-2 px-4 sm:inset-x-auto sm:right-4 sm:items-end"
     aria-live="polite">
    <template x-for="toast in items" :key="toast.id">
        <div x-show="toast.visible" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="translate-y-2 opacity-0" x-transition:enter-end="translate-y-0 opacity-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-end="opacity-0"
             class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-xl border bg-white p-4 shadow-lg dark:bg-slate-800"
             :class="{
                'border-emerald-200 dark:border-emerald-800': toast.type === 'success',
                'border-rose-200 dark:border-rose-800': toast.type === 'error',
                'border-amber-200 dark:border-amber-800': toast.type === 'warning',
                'border-sky-200 dark:border-sky-800': toast.type === 'info',
             }">
            <span class="mt-0.5 h-2.5 w-2.5 shrink-0 rounded-full"
                  :class="{ 'bg-emerald-500': toast.type === 'success', 'bg-rose-500': toast.type === 'error', 'bg-amber-500': toast.type === 'warning', 'bg-sky-500': toast.type === 'info' }"></span>
            <p class="flex-1 text-sm text-slate-700 dark:text-slate-200" x-text="toast.message"></p>
            <button type="button" @click="dismiss(toast.id)" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200" aria-label="Dismiss">
                <x-icon name="x" class="h-4 w-4" />
            </button>
        </div>
    </template>
</div>
