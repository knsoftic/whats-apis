<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · ' : '' }}KN Softic – WhatsApp Cloud API Manager</title>

    {{-- Apply saved theme before paint (no flash). --}}
    <script>
        (function () {
            try {
                var t = localStorage.getItem('theme');
                if (t === 'dark' || (!t && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                    document.documentElement.classList.add('dark');
                }
            } catch (e) {}
        })();
    </script>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="h-full bg-slate-50 font-sans text-slate-800 antialiased dark:bg-slate-950 dark:text-slate-200" x-data="{ sidebarOpen: false }">
<div class="flex h-full">
    {{-- Mobile overlay --}}
    <div x-show="sidebarOpen" x-transition.opacity @click="sidebarOpen = false" class="fixed inset-0 z-30 bg-slate-900/60 lg:hidden" x-cloak></div>

    {{-- Sidebar --}}
    <aside :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
           class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col border-r border-slate-200 bg-white transition-transform duration-200 dark:border-slate-800 dark:bg-slate-900 lg:static lg:translate-x-0">
        @include('layouts.sidebar')
    </aside>

    {{-- Main column --}}
    <div class="flex min-w-0 flex-1 flex-col">
        <header class="sticky top-0 z-20 flex h-16 shrink-0 items-center gap-3 border-b border-slate-200 bg-white/90 px-4 backdrop-blur dark:border-slate-800 dark:bg-slate-900/90 sm:px-6">
            <button type="button" @click="sidebarOpen = true" class="-ml-1 rounded-lg p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 lg:hidden" aria-label="Open menu">
                <x-icon name="menu" class="h-6 w-6" />
            </button>

            <div class="min-w-0 flex-1">
                @isset($header)
                    {{ $header }}
                @else
                    <p class="truncate text-base font-semibold text-slate-900 dark:text-white">{{ $title ?? 'Dashboard' }}</p>
                @endisset
            </div>

            @include('layouts.partials.account-switcher')

            {{-- Theme toggle --}}
            <button type="button" x-data="themeToggle()" @click="toggle()" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800" :title="dark ? 'Light mode' : 'Dark mode'" aria-label="Toggle dark mode">
                <span x-show="!dark"><x-icon name="moon" /></span>
                <span x-show="dark" x-cloak><x-icon name="sun" /></span>
            </button>

            {{-- User menu --}}
            <x-dropdown align="right" width="48">
                <x-slot name="trigger">
                    <button type="button" class="flex items-center gap-2 rounded-lg p-1.5 hover:bg-slate-100 dark:hover:bg-slate-800">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-emerald-600 text-sm font-semibold text-white">
                            {{ mb_strtoupper(mb_substr(Auth::user()->name, 0, 1)) }}
                        </span>
                        <span class="hidden text-left sm:block">
                            <span class="block max-w-[140px] truncate text-sm font-medium text-slate-800 dark:text-slate-100">{{ Auth::user()->name }}</span>
                            <span class="block text-xs text-slate-500 dark:text-slate-400">{{ Auth::user()->roleName() }}</span>
                        </span>
                        <x-icon name="chevron-down" class="hidden h-4 w-4 text-slate-400 sm:block" />
                    </button>
                </x-slot>
                <x-slot name="content">
                    <x-dropdown-link :href="route('profile.edit')">Profile</x-dropdown-link>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <x-dropdown-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();">Log out</x-dropdown-link>
                    </form>
                </x-slot>
            </x-dropdown>
        </header>

        <main @class([
            'flex-1 min-h-0',
            'overflow-hidden' => $full,
            'overflow-y-auto px-4 py-6 sm:px-6 lg:px-8' => ! $full,
        ])>
            {{ $slot }}
        </main>
    </div>
</div>

<x-toasts />
@stack('scripts')
</body>
</html>
