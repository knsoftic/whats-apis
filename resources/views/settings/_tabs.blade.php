@php
    $tabs = [
        'general' => ['General', 'home'],
        'appearance' => ['Appearance', 'sun'],
        'payments' => ['Payments', 'chart'],
        'email' => ['Email (SMTP)', 'envelope'],
        'legal' => ['Legal pages', 'template'],
        'meta' => ['Meta Tech Provider', 'key'],
    ];
    $current = request()->routeIs('settings.meta') ? 'meta' : ($section ?? 'general');
@endphp
<div class="mb-6">
    <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-white">Settings</h1>
    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Website, appearance, payments, email, legal pages and the Meta Tech Provider app.</p>
    <nav class="mt-4 flex gap-1 overflow-x-auto border-b border-slate-200 dark:border-slate-800">
        @foreach ($tabs as $key => [$label, $icon])
            <a href="{{ $key === 'meta' ? route('settings.meta') : route('settings.edit', $key) }}"
               @class(['flex shrink-0 items-center gap-2 border-b-2 px-3 py-2 text-sm font-medium',
                   'border-brand-600 text-brand-700 dark:text-brand-400' => $current === $key,
                   'border-transparent text-slate-500 hover:text-slate-800 dark:hover:text-slate-200' => $current !== $key])>
                <x-icon :name="$icon" class="h-4 w-4" />{{ $label }}
            </a>
        @endforeach
    </nav>
</div>
