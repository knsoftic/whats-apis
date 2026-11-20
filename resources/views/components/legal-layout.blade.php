@props(['title'])
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · {{ config('legal.product') }}</title>
    <meta name="description" content="{{ $title }} of {{ config('legal.product') }} ({{ config('legal.website') }}).">
    @include('layouts.partials.brand-head')
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-full bg-slate-50 font-sans text-slate-800 antialiased dark:bg-slate-950 dark:text-slate-200">
    <header class="border-b border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <div class="mx-auto flex max-w-4xl flex-wrap items-center justify-between gap-3 px-4 py-4 sm:px-6">
            <a href="{{ url('/') }}" class="flex items-center gap-3">
                <x-application-logo class="h-9 w-9" />
                <span class="leading-tight">
                    <span class="block text-sm font-bold text-slate-900 dark:text-white">{{ config('legal.company') }}</span>
                    <span class="block text-[11px] text-slate-500">WhatsApp Cloud API Manager</span>
                </span>
            </a>
            <nav class="flex gap-4 text-sm">
                <a href="{{ route('legal.privacy') }}" @class(['font-medium', 'text-brand-700 dark:text-brand-400' => request()->routeIs('legal.privacy'), 'text-slate-600 hover:text-slate-900 dark:text-slate-400' => ! request()->routeIs('legal.privacy')])>Privacy Policy</a>
                <a href="{{ route('legal.terms') }}" @class(['font-medium', 'text-brand-700 dark:text-brand-400' => request()->routeIs('legal.terms'), 'text-slate-600 hover:text-slate-900 dark:text-slate-400' => ! request()->routeIs('legal.terms')])>Terms &amp; Conditions</a>
                <a href="{{ route('login') }}" class="text-slate-600 hover:text-slate-900 dark:text-slate-400">Sign in</a>
            </nav>
        </div>
    </header>

    <main class="mx-auto max-w-4xl px-4 py-10 sm:px-6">
        <article class="legal rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-10">
            <h1>{{ $title }}</h1>
            <p class="!mt-1 text-sm text-slate-500">Effective date: {{ \Illuminate\Support\Carbon::parse(config('legal.effective_date'))->format('F j, Y') }} · {{ config('legal.website') }}</p>
            {{ $slot }}
        </article>
    </main>

    <footer class="mx-auto max-w-4xl px-4 pb-10 text-xs text-slate-500 sm:px-6">
        © {{ date('Y') }} {{ config('legal.company') }}. WhatsApp is a trademark of WhatsApp LLC / Meta Platforms, Inc. {{ config('legal.company') }} is an independent service and is not affiliated with or endorsed by Meta.
    </footer>
</body>
</html>
