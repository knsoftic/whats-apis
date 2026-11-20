@php
    use App\Support\LandingContent as LC;

    $hero = $c['hero'];
    $contact = $c['contact'];
    $siteName = $brand->siteName();
    $pageTitle = $c['seo']['title'] ?: $siteName.' – '.$brand->tagline();
    $ogImage = LC::mediaUrl($c['seo']['og_image']) ?: LC::mediaUrl($hero['image']) ?: $brand->logoUrl();

    $contactEmail = $contact['email'] ?: $brand->get('site.support_email');
    $contactPhone = $contact['phone'] ?: $brand->get('site.support_phone');
    $whatsapp = ltrim((string) $contact['whatsapp'], '+');

    $showPricing = $c['pricing']['enabled'] && $plans->isNotEmpty();
    $videoId = LC::youtubeId((string) $c['video']['youtube']);
    $videoFile = LC::mediaUrl($c['video']['file']);
    $showVideo = $c['video']['enabled'] && ($videoId || $videoFile);

    $nav = array_filter([
        'features' => $c['features']['enabled'] && $c['features']['items'] ? 'Features' : null,
        'how-it-works' => $c['steps']['enabled'] && $c['steps']['items'] ? 'How it works' : null,
        'pricing' => $showPricing ? 'Pricing' : null,
        'faq' => $c['faq']['enabled'] && $c['faq']['items'] ? 'FAQ' : null,
        'contact' => $contact['enabled'] ? 'Contact' : null,
    ]);

    // Main call-to-action target.
    if (auth()->check()) {
        [$ctaUrl, $ctaLabel] = [route('dashboard'), 'Open dashboard'];
    } elseif ($registrationOpen) {
        [$ctaUrl, $ctaLabel] = [route('register'), $hero['primary_text'] ?: 'Register'];
    } else {
        [$ctaUrl, $ctaLabel] = [route('login'), 'Login'];
    }
    $secondaryUrl = $showVideo ? '#video' : (isset($nav['how-it-works']) ? '#how-it-works' : (isset($nav['features']) ? '#features' : null));

    // Hero media.
    $heroYoutube = $hero['media_type'] === 'youtube' ? LC::youtubeId((string) $hero['youtube']) : null;
    $heroVideo = $hero['media_type'] === 'video' ? LC::mediaUrl($hero['video']) : null;
    $heroImage = LC::mediaUrl($hero['image']);
    $hasMedia = $hero['media_type'] !== 'none';

    $btnPrimary = 'inline-flex items-center justify-center gap-2 rounded-xl bg-brand-600 font-semibold text-white shadow-sm shadow-brand-600/20 transition hover:bg-brand-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-slate-950';
    $btnSecondary = 'inline-flex items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800';
@endphp
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ $c['seo']['description'] }}">
    <link rel="canonical" href="{{ url('/') }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $siteName }}">
    <meta property="og:title" content="{{ $pageTitle }}">
    <meta property="og:description" content="{{ $c['seo']['description'] }}">
    <meta property="og:url" content="{{ url('/') }}">
    @if ($ogImage)
        <meta property="og:image" content="{{ $ogImage }}">
    @endif
    <meta name="twitter:card" content="{{ $ogImage ? 'summary_large_image' : 'summary' }}">
    @include('layouts.partials.brand-head')
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/landing.js'])
</head>
<body class="bg-white font-sans text-slate-700 antialiased dark:bg-slate-950 dark:text-slate-300">

{{-- ============================================================ Header --}}
<header x-data="landingNav" class="sticky top-0 z-40 transition" :class="scrolled || open ? 'border-b border-slate-200/80 bg-white/90 backdrop-blur dark:border-slate-800 dark:bg-slate-950/90' : 'border-b border-transparent bg-white/0'">
    <div class="mx-auto flex h-16 max-w-6xl items-center gap-3 px-4 sm:px-6">
        <a href="{{ url('/') }}" class="flex min-w-0 items-center gap-2.5">
            <x-application-logo class="h-9 w-9 shrink-0" />
            <span class="hidden truncate text-base font-bold text-slate-900 dark:text-white min-[400px]:block">{{ $siteName }}</span>
        </a>

        <nav class="ml-6 hidden items-center gap-1 lg:flex" aria-label="Main">
            @foreach ($nav as $anchor => $label)
                <a href="#{{ $anchor }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white">{{ $label }}</a>
            @endforeach
        </nav>

        <div class="ml-auto flex items-center gap-1.5 sm:gap-2">
            @if ($brand->bool('theme.allow_toggle'))
                <button type="button" x-data="themeToggle" @click="toggle()" class="hidden rounded-lg p-2 text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800 sm:block" aria-label="Toggle dark mode">
                    <x-icon name="moon" class="h-5 w-5" x-show="!dark" />
                    <x-icon name="sun" class="h-5 w-5" x-show="dark" x-cloak />
                </button>
            @endif

            @auth
                <a href="{{ route('dashboard') }}" class="{{ $btnPrimary }} px-3.5 py-2 text-sm">Dashboard</a>
            @else
                <a href="{{ route('login') }}" class="rounded-xl px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800">Login</a>
                @if ($registrationOpen)
                    <a href="{{ route('register') }}" class="{{ $btnPrimary }} px-3.5 py-2 text-sm">Register</a>
                @endif
            @endauth

            @if ($nav)
                <button type="button" @click="open = !open" class="rounded-lg p-2 text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800 lg:hidden" :aria-expanded="open" aria-label="Menu">
                    <x-icon name="menu" class="h-5 w-5" x-show="!open" />
                    <x-icon name="x" class="h-5 w-5" x-show="open" x-cloak />
                </button>
            @endif
        </div>
    </div>

    @if ($nav)
        <nav x-show="open" x-cloak x-transition.opacity @click.outside="open = false" class="border-t border-slate-200 px-4 pb-4 pt-2 dark:border-slate-800 lg:hidden" aria-label="Mobile">
            @foreach ($nav as $anchor => $label)
                <a href="#{{ $anchor }}" @click="open = false" class="block rounded-lg px-3 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800">{{ $label }}</a>
            @endforeach
            @if ($brand->bool('theme.allow_toggle'))
                <button type="button" x-data="themeToggle" @click="toggle()" class="flex w-full items-center gap-2 rounded-lg px-3 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800 sm:hidden">
                    <x-icon name="moon" class="h-4 w-4" x-show="!dark" /><x-icon name="sun" class="h-4 w-4" x-show="dark" x-cloak />
                    <span x-text="dark ? 'Light mode' : 'Dark mode'">Dark mode</span>
                </button>
            @endif
        </nav>
    @endif
</header>

<main>
{{-- ============================================================ Hero --}}
@if ($hero['enabled'])
<section class="relative overflow-hidden">
    <div class="pointer-events-none absolute inset-0 -z-10" aria-hidden="true">
        <div class="absolute -top-40 left-1/2 h-[36rem] w-[36rem] -translate-x-1/2 rounded-full bg-brand-400/20 blur-3xl dark:bg-brand-600/20 lg:left-1/4"></div>
        <div class="absolute right-0 top-40 h-72 w-72 rounded-full bg-brand-200/40 blur-3xl dark:bg-brand-900/30"></div>
    </div>

    <div @class(['mx-auto max-w-6xl px-4 pb-16 pt-12 sm:px-6 sm:pt-16 lg:pb-24', 'grid items-center gap-12 lg:grid-cols-2' => $hasMedia, 'text-center' => ! $hasMedia])>
        <div @class(['mx-auto max-w-3xl' => ! $hasMedia])>
            @if ($hero['badge'])
                <span class="inline-flex items-center gap-2 rounded-full border border-brand-200 bg-brand-50 px-3 py-1 text-xs font-semibold text-brand-700 dark:border-brand-800 dark:bg-brand-950/60 dark:text-brand-300">
                    <span class="h-1.5 w-1.5 rounded-full bg-brand-500"></span>{{ $hero['badge'] }}
                </span>
            @endif
            <h1 class="mt-5 text-4xl font-extrabold tracking-tight text-slate-900 dark:text-white sm:text-5xl lg:text-[3.4rem] lg:leading-[1.08]">{{ $hero['title'] }}</h1>
            @if ($hero['subtitle'])
                <p @class(['mt-5 text-lg leading-8 text-slate-600 dark:text-slate-400', 'mx-auto max-w-2xl' => ! $hasMedia])>{{ $hero['subtitle'] }}</p>
            @endif
            <div @class(['mt-8 flex flex-wrap gap-3', 'justify-center' => ! $hasMedia])>
                <a href="{{ $ctaUrl }}" class="{{ $btnPrimary }} px-6 py-3 text-base">{{ $ctaLabel }} <span aria-hidden="true">→</span></a>
                @if ($hero['secondary_text'] && $secondaryUrl)
                    <a href="{{ $secondaryUrl }}" class="{{ $btnSecondary }} px-6 py-3 text-base">{{ $hero['secondary_text'] }}</a>
                @endif
            </div>
        </div>

        @if ($hasMedia)
            <div class="relative">
                @if ($heroYoutube)
                    <div class="aspect-video overflow-hidden rounded-2xl border border-slate-200 bg-black shadow-2xl shadow-slate-900/10 dark:border-slate-800">
                        <iframe class="h-full w-full" src="https://www.youtube-nocookie.com/embed/{{ $heroYoutube }}?rel=0" title="{{ $hero['title'] }}" loading="lazy" allow="accelerometer; encrypted-media; gyroscope; picture-in-picture; fullscreen" allowfullscreen></iframe>
                    </div>
                @elseif ($heroVideo)
                    <video class="w-full rounded-2xl border border-slate-200 shadow-2xl shadow-slate-900/10 dark:border-slate-800" src="{{ $heroVideo }}" @if ($heroImage) poster="{{ $heroImage }}" @endif autoplay muted loop playsinline></video>
                @elseif ($hero['media_type'] === 'image' && $heroImage)
                    <img class="w-full rounded-2xl border border-slate-200 shadow-2xl shadow-slate-900/10 dark:border-slate-800" src="{{ $heroImage }}" alt="{{ $hero['title'] }}">
                @else
                    @include('landing.partials.inbox-mockup')
                @endif
            </div>
        @endif
    </div>
</section>
@endif

{{-- ============================================================ Stats --}}
@if ($c['stats']['enabled'] && $c['stats']['items'])
<section class="border-y border-slate-200 bg-slate-50/70 dark:border-slate-800 dark:bg-slate-900/40">
    <dl class="mx-auto grid max-w-6xl grid-cols-2 gap-x-6 gap-y-8 px-4 py-10 sm:px-6 {{ [1 => 'md:grid-cols-1', 2 => 'md:grid-cols-2', 3 => 'md:grid-cols-3', 4 => 'md:grid-cols-4', 5 => 'md:grid-cols-5', 6 => 'md:grid-cols-6'][count($c['stats']['items'])] }}">
        @foreach ($c['stats']['items'] as $stat)
            <div class="flex flex-col-reverse text-center">
                <dt class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $stat['label'] }}</dt>
                <dd class="text-3xl font-extrabold tracking-tight text-slate-900 dark:text-white">{{ $stat['value'] }}</dd>
            </div>
        @endforeach
    </dl>
</section>
@endif

{{-- ============================================================ Features --}}
@if (isset($nav['features']))
<section id="features" class="scroll-mt-20 py-20 sm:py-24">
    <div class="mx-auto max-w-6xl px-4 sm:px-6">
        <div class="mx-auto max-w-2xl text-center">
            <h2 class="text-3xl font-bold tracking-tight text-slate-900 dark:text-white sm:text-4xl">{{ $c['features']['title'] }}</h2>
            @if ($c['features']['subtitle'])<p class="mt-4 text-lg text-slate-600 dark:text-slate-400">{{ $c['features']['subtitle'] }}</p>@endif
        </div>
        <div class="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($c['features']['items'] as $feature)
                <div class="group rounded-2xl border border-slate-200 bg-white p-6 transition hover:-translate-y-0.5 hover:border-brand-300 hover:shadow-lg hover:shadow-brand-600/5 dark:border-slate-800 dark:bg-slate-900 dark:hover:border-brand-700">
                    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-50 text-brand-600 ring-1 ring-brand-100 dark:bg-brand-950 dark:text-brand-400 dark:ring-brand-900">
                        <x-icon :name="$feature['icon']" class="h-5 w-5" />
                    </span>
                    <h3 class="mt-4 text-base font-semibold text-slate-900 dark:text-white">{{ $feature['title'] }}</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-400">{{ $feature['text'] }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>
@endif

{{-- ============================================================ Steps --}}
@if (isset($nav['how-it-works']))
<section id="how-it-works" class="scroll-mt-20 bg-slate-50 py-20 dark:bg-slate-900/40 sm:py-24">
    <div class="mx-auto max-w-6xl px-4 sm:px-6">
        <h2 class="text-center text-3xl font-bold tracking-tight text-slate-900 dark:text-white sm:text-4xl">{{ $c['steps']['title'] }}</h2>
        <ol class="mt-14 grid gap-6 {{ [1 => 'mx-auto max-w-md', 2 => 'md:grid-cols-2'][count($c['steps']['items'])] ?? 'md:grid-cols-3' }}">
            @foreach ($c['steps']['items'] as $i => $step)
                <li class="relative rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-brand-600 text-sm font-bold text-white">{{ $i + 1 }}</span>
                    <h3 class="mt-4 text-base font-semibold text-slate-900 dark:text-white">{{ $step['title'] }}</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-400">{{ $step['text'] }}</p>
                </li>
            @endforeach
        </ol>
    </div>
</section>
@endif

{{-- ============================================================ Video --}}
@if ($showVideo)
<section id="video" class="scroll-mt-20 py-20 sm:py-24">
    <div class="mx-auto max-w-4xl px-4 sm:px-6">
        <div class="mx-auto max-w-2xl text-center">
            <h2 class="text-3xl font-bold tracking-tight text-slate-900 dark:text-white sm:text-4xl">{{ $c['video']['title'] }}</h2>
            @if ($c['video']['subtitle'])<p class="mt-4 text-lg text-slate-600 dark:text-slate-400">{{ $c['video']['subtitle'] }}</p>@endif
        </div>
        <div class="mt-10 aspect-video overflow-hidden rounded-2xl border border-slate-200 bg-black shadow-2xl shadow-slate-900/10 dark:border-slate-800">
            @if ($videoId)
                <iframe class="h-full w-full" src="https://www.youtube-nocookie.com/embed/{{ $videoId }}?rel=0" title="{{ $c['video']['title'] }}" loading="lazy" allow="accelerometer; encrypted-media; gyroscope; picture-in-picture; fullscreen" allowfullscreen></iframe>
            @else
                <video class="h-full w-full" src="{{ $videoFile }}" controls preload="metadata" playsinline></video>
            @endif
        </div>
    </div>
</section>
@endif

{{-- ============================================================ Pricing --}}
@if ($showPricing)
<section id="pricing" class="scroll-mt-20 py-20 sm:py-24 {{ $showVideo ? 'bg-slate-50 dark:bg-slate-900/40' : '' }}">
    <div class="mx-auto max-w-6xl px-4 sm:px-6">
        <div class="mx-auto max-w-2xl text-center">
            <h2 class="text-3xl font-bold tracking-tight text-slate-900 dark:text-white sm:text-4xl">{{ $c['pricing']['title'] }}</h2>
            @if ($c['pricing']['subtitle'])<p class="mt-4 text-lg text-slate-600 dark:text-slate-400">{{ $c['pricing']['subtitle'] }}</p>@endif
        </div>
        <div @class(['mx-auto mt-14 grid gap-6', 'max-w-md' => $plans->count() === 1, 'max-w-3xl md:grid-cols-2' => $plans->count() === 2, 'md:grid-cols-2 lg:grid-cols-3' => $plans->count() >= 3])>
            @foreach ($plans as $plan)
                <div class="flex flex-col rounded-2xl border border-slate-200 bg-white p-7 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-white">{{ $plan->name }}</h3>
                    @if ($plan->description)<p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $plan->description }}</p>@endif
                    <p class="mt-5 text-3xl font-extrabold tracking-tight text-slate-900 dark:text-white">{{ $plan->priceLabel() }}</p>
                    @if ($plan->trial_days)<p class="mt-1 text-xs font-medium text-brand-600 dark:text-brand-400">{{ $plan->trial_days }}-day free trial</p>@endif
                    <ul class="mt-6 space-y-2.5 text-sm">
                        @foreach ([
                            [true, \App\Models\Plan::limitLabel($plan->max_whatsapp_accounts).' WhatsApp number(s)'],
                            [true, \App\Models\Plan::limitLabel($plan->daily_message_limit).' messages / day'],
                            [true, \App\Models\Plan::limitLabel($plan->max_contacts).' contacts'],
                            [$plan->allow_embedded_signup, 'Connect with Meta (one click)'],
                            [$plan->allow_manual, 'Manual API connection'],
                            [$plan->allow_bot_flows, 'Bot flows'.($plan->allow_bot_flows && $plan->max_bot_flows !== null ? ' ('.$plan->max_bot_flows.')' : '')],
                        ] as [$on, $label])
                            <li @class(['flex gap-2.5', 'text-slate-400 dark:text-slate-600' => ! $on])>
                                <x-icon :name="$on ? 'check' : 'x'" :class="$on ? 'h-5 w-5 shrink-0 text-brand-600 dark:text-brand-400' : 'h-5 w-5 shrink-0'" />
                                <span @class(['line-through' => ! $on])>{{ $label }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <div class="mt-auto pt-7">
                        <a href="{{ $ctaUrl }}" class="{{ (float) $plan->price > 0 ? $btnPrimary : $btnSecondary }} w-full px-4 py-2.5 text-sm">{{ auth()->check() ? 'Open dashboard' : ((float) $plan->price > 0 ? 'Get '.$plan->name : 'Start free') }}</a>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>
@endif

{{-- ============================================================ Testimonials --}}
@if ($c['testimonials']['enabled'] && $c['testimonials']['items'])
<section class="py-20 sm:py-24 {{ $showPricing && ! $showVideo ? 'bg-slate-50 dark:bg-slate-900/40' : '' }}">
    <div class="mx-auto max-w-6xl px-4 sm:px-6">
        <h2 class="text-center text-3xl font-bold tracking-tight text-slate-900 dark:text-white sm:text-4xl">{{ $c['testimonials']['title'] }}</h2>
        <div class="mt-14 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
            @foreach ($c['testimonials']['items'] as $t)
                @php $initials = mb_strtoupper(collect(preg_split('/\s+/', trim($t['name'] ?: '?')))->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('')); @endphp
                <figure class="flex flex-col rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex gap-0.5 text-amber-400" aria-hidden="true">@for ($s = 0; $s < 5; $s++)<svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 1.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L10 14.9l-5.2 2.7 1-5.8L1.5 7.7l5.9-.9L10 1.5z"/></svg>@endfor</div>
                    <blockquote class="mt-4 flex-1 text-[15px] leading-7 text-slate-700 dark:text-slate-300">“{{ $t['text'] }}”</blockquote>
                    <figcaption class="mt-6 flex items-center gap-3">
                        <span class="flex h-10 w-10 items-center justify-center rounded-full bg-brand-100 text-sm font-bold text-brand-700 dark:bg-brand-900 dark:text-brand-200">{{ $initials }}</span>
                        <span><span class="block text-sm font-semibold text-slate-900 dark:text-white">{{ $t['name'] }}</span>@if ($t['role'])<span class="block text-xs text-slate-500">{{ $t['role'] }}</span>@endif</span>
                    </figcaption>
                </figure>
            @endforeach
        </div>
    </div>
</section>
@endif

{{-- ============================================================ FAQ --}}
@if (isset($nav['faq']))
<section id="faq" class="scroll-mt-20 py-20 sm:py-24">
    <div class="mx-auto max-w-3xl px-4 sm:px-6">
        <h2 class="text-center text-3xl font-bold tracking-tight text-slate-900 dark:text-white sm:text-4xl">{{ $c['faq']['title'] }}</h2>
        <div class="mt-12 divide-y divide-slate-200 rounded-2xl border border-slate-200 bg-white dark:divide-slate-800 dark:border-slate-800 dark:bg-slate-900">
            @foreach ($c['faq']['items'] as $faq)
                <details class="group px-6 py-5" @if ($loop->first) open @endif>
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-4 text-left font-semibold text-slate-900 dark:text-white [&::-webkit-details-marker]:hidden">
                        {{ $faq['q'] }}
                        <x-icon name="chevron-down" class="h-5 w-5 shrink-0 text-slate-400 transition group-open:rotate-180" />
                    </summary>
                    <p class="mt-3 whitespace-pre-line text-[15px] leading-7 text-slate-600 dark:text-slate-400">{{ $faq['a'] }}</p>
                </details>
            @endforeach
        </div>
    </div>
</section>
@endif

{{-- ============================================================ Contact --}}
@if ($contact['enabled'])
<section id="contact" class="scroll-mt-20 bg-slate-50 py-20 dark:bg-slate-900/40 sm:py-24">
    <div @class(['mx-auto max-w-6xl px-4 sm:px-6', 'grid gap-12 lg:grid-cols-5' => $contact['show_form']])>
        <div @class(['lg:col-span-2' => $contact['show_form'], 'mx-auto max-w-2xl text-center' => ! $contact['show_form']])>
            <h2 class="text-3xl font-bold tracking-tight text-slate-900 dark:text-white sm:text-4xl">{{ $contact['title'] }}</h2>
            @if ($contact['subtitle'])<p class="mt-4 text-lg text-slate-600 dark:text-slate-400">{{ $contact['subtitle'] }}</p>@endif
            <ul @class(['mt-8 space-y-4 text-sm', 'inline-block text-left' => ! $contact['show_form']])>
                @if ($contactEmail)
                    <li class="flex items-center gap-3"><span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white text-brand-600 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-brand-400 dark:ring-slate-800"><x-icon name="envelope" class="h-5 w-5" /></span><a href="mailto:{{ $contactEmail }}" class="font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-400">{{ $contactEmail }}</a></li>
                @endif
                @if ($contactPhone)
                    <li class="flex items-center gap-3"><span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white text-brand-600 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-brand-400 dark:ring-slate-800"><x-icon name="phone" class="h-5 w-5" /></span><a href="tel:{{ preg_replace('/[^0-9+]/', '', $contactPhone) }}" class="font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-400">{{ $contactPhone }}</a></li>
                @endif
                @if ($whatsapp)
                    <li class="flex items-center gap-3"><span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white text-brand-600 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-brand-400 dark:ring-slate-800"><x-icon name="chat" class="h-5 w-5" /></span><a href="https://wa.me/{{ $whatsapp }}" target="_blank" rel="noopener" class="font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-400">WhatsApp +{{ $whatsapp }}</a></li>
                @endif
                @if ($contact['address'])
                    <li class="flex items-start gap-3"><span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white text-brand-600 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-brand-400 dark:ring-slate-800"><x-icon name="map-pin" class="h-5 w-5" /></span><span class="whitespace-pre-line pt-2.5 text-slate-700 dark:text-slate-300">{{ $contact['address'] }}</span></li>
                @endif
            </ul>
        </div>

        @if ($contact['show_form'])
            <div class="lg:col-span-3">
                @if (session('contact_sent'))
                    <div class="flex items-start gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-6 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-300" role="status">
                        <x-icon name="check" class="h-6 w-6 shrink-0" />
                        <div><p class="font-semibold">Message sent — thank you!</p><p class="mt-1 text-sm">We'll get back to you as soon as possible.</p></div>
                    </div>
                @else
                    @php $err = $errors->getBag('contact'); @endphp
                    <form method="POST" action="{{ route('landing.contact') }}" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-8" x-data="{ sending: false }" @submit="sending = true">
                        @csrf
                        <div class="grid gap-5 sm:grid-cols-2">
                            <div>
                                <label for="c_name" class="form-label">Your name</label>
                                <input id="c_name" name="name" type="text" class="form-input" value="{{ old('name') }}" required maxlength="100" autocomplete="name">
                                @if ($err->has('name'))<p class="form-error">{{ $err->first('name') }}</p>@endif
                            </div>
                            <div>
                                <label for="c_email" class="form-label">Email</label>
                                <input id="c_email" name="email" type="email" class="form-input" value="{{ old('email') }}" required maxlength="150" autocomplete="email">
                                @if ($err->has('email'))<p class="form-error">{{ $err->first('email') }}</p>@endif
                            </div>
                            <div class="sm:col-span-2">
                                <label for="c_phone" class="form-label">Phone / WhatsApp <span class="font-normal text-slate-400">(optional)</span></label>
                                <input id="c_phone" name="phone" type="tel" class="form-input" value="{{ old('phone') }}" maxlength="30" autocomplete="tel">
                                @if ($err->has('phone'))<p class="form-error">{{ $err->first('phone') }}</p>@endif
                            </div>
                            <div class="sm:col-span-2">
                                <label for="c_message" class="form-label">Message</label>
                                <textarea id="c_message" name="message" rows="5" class="form-input" required maxlength="3000">{{ old('message') }}</textarea>
                                @if ($err->has('message'))<p class="form-error">{{ $err->first('message') }}</p>@endif
                            </div>
                            {{-- Honeypot: humans never see or fill this. --}}
                            <div class="absolute -left-[9999px] h-px w-px overflow-hidden" aria-hidden="true">
                                <label for="c_website">Website</label>
                                <input id="c_website" name="website" type="text" tabindex="-1" autocomplete="off">
                            </div>
                        </div>
                        <button type="submit" class="{{ $btnPrimary }} mt-6 w-full px-6 py-3 text-sm sm:w-auto" :disabled="sending">
                            <span x-show="sending" x-cloak class="spinner"></span> Send message
                        </button>
                    </form>
                @endif
            </div>
        @endif
    </div>
</section>
@endif

{{-- ============================================================ CTA --}}
@if ($c['cta']['enabled'])
<section class="py-20 sm:py-24">
    <div class="mx-auto max-w-6xl px-4 sm:px-6">
        <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-brand-600 via-brand-700 to-brand-900 px-6 py-14 text-center shadow-xl sm:px-12">
            <div class="pointer-events-none absolute -right-20 -top-20 h-72 w-72 rounded-full bg-white/10 blur-2xl" aria-hidden="true"></div>
            <h2 class="relative text-3xl font-bold tracking-tight text-white sm:text-4xl">{{ $c['cta']['title'] }}</h2>
            @if ($c['cta']['subtitle'])<p class="relative mx-auto mt-4 max-w-xl text-lg text-brand-100">{{ $c['cta']['subtitle'] }}</p>@endif
            <a href="{{ $ctaUrl }}" class="relative mt-8 inline-flex items-center justify-center gap-2 rounded-xl bg-white px-6 py-3 text-base font-semibold text-brand-700 shadow-sm transition hover:bg-brand-50">
                {{ auth()->check() ? 'Open dashboard' : ($registrationOpen ? ($c['cta']['button'] ?: 'Register') : 'Login') }} <span aria-hidden="true">→</span>
            </a>
        </div>
    </div>
</section>
@endif
</main>

{{-- ============================================================ Footer --}}
<footer class="border-t border-slate-200 dark:border-slate-800">
    <div class="mx-auto max-w-6xl px-4 py-12 sm:px-6">
        <div class="flex flex-col gap-8 md:flex-row md:items-start md:justify-between">
            <div class="max-w-sm">
                <a href="{{ url('/') }}" class="flex items-center gap-2.5">
                    <x-application-logo class="h-9 w-9" />
                    <span class="leading-tight"><span class="block font-bold text-slate-900 dark:text-white">{{ $siteName }}</span><span class="block text-xs text-slate-500">{{ $brand->tagline() }}</span></span>
                </a>
                @if ($c['footer']['text'])<p class="mt-4 text-sm text-slate-500 dark:text-slate-400">{{ $c['footer']['text'] }}</p>@endif
            </div>
            <nav class="grid grid-cols-2 gap-x-12 gap-y-2 text-sm sm:grid-cols-3" aria-label="Footer">
                @foreach ($nav as $anchor => $label)
                    <a href="#{{ $anchor }}" class="text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white">{{ $label }}</a>
                @endforeach
                <a href="{{ route('legal.privacy') }}" class="text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white">Privacy Policy</a>
                <a href="{{ route('legal.terms') }}" class="text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white">Terms &amp; Conditions</a>
                @guest
                    <a href="{{ route('login') }}" class="text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white">Login</a>
                    @if ($registrationOpen)<a href="{{ route('register') }}" class="text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white">Register</a>@endif
                @endguest
            </nav>
        </div>
        <p class="mt-10 border-t border-slate-200 pt-6 text-xs leading-5 text-slate-500 dark:border-slate-800">
            © {{ date('Y') }} {{ config('legal.company') ?: $siteName }}. WhatsApp is a trademark of WhatsApp LLC / Meta Platforms, Inc. {{ $siteName }} is an independent service and is not affiliated with or endorsed by Meta.
        </p>
    </div>
</footer>

@if ($whatsapp)
    <a href="https://wa.me/{{ $whatsapp }}" target="_blank" rel="noopener" class="fixed bottom-5 right-5 z-40 flex h-14 w-14 items-center justify-center rounded-full bg-[#25D366] text-white shadow-lg shadow-black/20 transition hover:scale-105" aria-label="Chat on WhatsApp">
        <svg class="h-7 w-7" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38a9.9 9.9 0 0 0 4.74 1.21c5.46 0 9.91-4.45 9.91-9.91C21.95 6.45 17.5 2 12.04 2Zm5.8 14.02c-.24.68-1.42 1.3-1.96 1.35-.5.05-1.13.07-1.83-.12-.42-.13-.96-.31-1.65-.61-2.9-1.25-4.79-4.17-4.94-4.36-.14-.19-1.18-1.57-1.18-3s.75-2.13 1.02-2.42c.27-.29.58-.36.78-.36h.56c.18 0 .42-.07.66.5.24.58.82 2.01.89 2.16.07.14.12.31.02.5-.1.19-.14.31-.29.48-.14.17-.3.37-.43.5-.14.14-.29.3-.13.59.17.29.74 1.22 1.59 1.98 1.09.97 2.01 1.27 2.3 1.42.29.14.46.12.63-.07.17-.19.72-.84.92-1.13.19-.29.38-.24.65-.14.26.1 1.68.79 1.97.94.29.14.48.22.55.34.07.12.07.7-.17 1.38Z"/></svg>
    </a>
@endif
</body>
</html>
