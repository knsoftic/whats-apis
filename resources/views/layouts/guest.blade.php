<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $brand->siteName() }} – {{ $brand->tagline() }}</title>
    @include('layouts.partials.brand-head')
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-50 font-sans text-slate-800 antialiased dark:bg-slate-950 dark:text-slate-200">
<div class="grid min-h-full lg:grid-cols-2">
    <div class="relative hidden overflow-hidden bg-gradient-to-br from-brand-600 via-brand-700 to-brand-950 lg:flex lg:flex-col lg:justify-between lg:p-12">
        <div class="flex items-center gap-3 text-white">
            <x-application-logo class="h-11 w-11" />
            <div>
                <p class="text-lg font-bold">{{ $brand->siteName() }}</p>
                <p class="text-sm text-brand-100">{{ $brand->tagline() }}</p>
            </div>
        </div>
        <div class="max-w-md text-white">
            <h2 class="text-3xl font-semibold leading-tight">All your WhatsApp Business numbers in one inbox.</h2>
            <ul class="mt-6 space-y-3 text-brand-50">
                <li class="flex gap-3"><x-icon name="check" class="h-5 w-5 shrink-0" /> Meta WhatsApp Cloud API — no QR codes, no unofficial libraries</li>
                <li class="flex gap-3"><x-icon name="check" class="h-5 w-5 shrink-0" /> Real-time chat, templates, media and delivery status</li>
                <li class="flex gap-3"><x-icon name="check" class="h-5 w-5 shrink-0" /> Encrypted credentials and per-number access control</li>
            </ul>
        </div>
        <p class="text-xs text-brand-100/80">© {{ date('Y') }} {{ $brand->siteName() }}</p>
        <div class="pointer-events-none absolute -right-24 -top-24 h-96 w-96 rounded-full bg-white/10 blur-3xl"></div>
    </div>

    <div class="flex items-center justify-center px-4 py-12 sm:px-8">
        <div class="w-full max-w-sm">
            <div class="mb-8 flex items-center gap-3 lg:hidden">
                <x-application-logo class="h-10 w-10" />
                <div>
                    <p class="font-bold text-slate-900 dark:text-white">{{ $brand->siteName() }}</p>
                    <p class="text-xs text-slate-500">{{ $brand->tagline() }}</p>
                </div>
            </div>
            {{ $slot }}
            <p class="mt-10 flex justify-center gap-4 text-xs text-slate-500">
                <a href="{{ route('legal.privacy') }}" class="hover:text-slate-800 dark:hover:text-slate-300">Privacy Policy</a>
                <a href="{{ route('legal.terms') }}" class="hover:text-slate-800 dark:hover:text-slate-300">Terms &amp; Conditions</a>
            </p>
            {{-- Clear ownership + anti-phishing note (this is an independent service, not WhatsApp / Meta). --}}
            <p class="mt-3 text-center text-[11px] leading-4 text-slate-400">
                {{ $brand->siteName() }} is an independent business software service{{ config('legal.company') && config('legal.company') !== $brand->siteName() ? ' by '.config('legal.company') : '' }}, not affiliated with WhatsApp or Meta.
                We never ask for your WhatsApp verification code or your Facebook password.
            </p>
        </div>
    </div>
</div>
</body>
</html>
