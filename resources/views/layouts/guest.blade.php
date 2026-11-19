<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Sign in · KN Softic – WhatsApp Cloud API Manager</title>
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
</head>
<body class="h-full bg-slate-50 font-sans text-slate-800 antialiased dark:bg-slate-950 dark:text-slate-200">
<div class="grid min-h-full lg:grid-cols-2">
    <div class="relative hidden overflow-hidden bg-gradient-to-br from-emerald-600 via-emerald-700 to-teal-900 lg:flex lg:flex-col lg:justify-between lg:p-12">
        <div class="flex items-center gap-3 text-white">
            <x-application-logo class="h-11 w-11" />
            <div>
                <p class="text-lg font-bold">KN Softic</p>
                <p class="text-sm text-emerald-100">WhatsApp Cloud API Manager</p>
            </div>
        </div>
        <div class="max-w-md text-white">
            <h2 class="text-3xl font-semibold leading-tight">All your official WhatsApp Business numbers in one inbox.</h2>
            <ul class="mt-6 space-y-3 text-emerald-50">
                <li class="flex gap-3"><x-icon name="check" class="h-5 w-5 shrink-0" /> Meta WhatsApp Cloud API — no QR codes, no unofficial libraries</li>
                <li class="flex gap-3"><x-icon name="check" class="h-5 w-5 shrink-0" /> Real-time chat, templates, media and delivery status</li>
                <li class="flex gap-3"><x-icon name="check" class="h-5 w-5 shrink-0" /> Encrypted credentials and per-number access control</li>
            </ul>
        </div>
        <p class="text-xs text-emerald-100/80">© {{ date('Y') }} KN Softic</p>
        <div class="pointer-events-none absolute -right-24 -top-24 h-96 w-96 rounded-full bg-white/10 blur-3xl"></div>
    </div>

    <div class="flex items-center justify-center px-4 py-12 sm:px-8">
        <div class="w-full max-w-sm">
            <div class="mb-8 flex items-center gap-3 lg:hidden">
                <x-application-logo class="h-10 w-10" />
                <div>
                    <p class="font-bold text-slate-900 dark:text-white">KN Softic</p>
                    <p class="text-xs text-slate-500">WhatsApp Cloud API Manager</p>
                </div>
            </div>
            {{ $slot }}
        </div>
    </div>
</div>
</body>
</html>
