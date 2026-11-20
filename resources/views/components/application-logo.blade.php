@php
    $b = $brand ?? app(\App\Support\PlatformSettings::class);
    $words = preg_split('/\s+/', trim($b->siteName())) ?: ['K'];
    $initials = mb_strtoupper(mb_substr($words[0] ?? 'K', 0, 1).mb_substr($words[1] ?? ($words[0] ?? 'N'), (isset($words[1]) ? 0 : 1), 1));
    $gid = 'lg'.substr(md5($attributes->get('class', '')), 0, 6);
@endphp
@if ($b->logoUrl())
    <img src="{{ $b->logoUrl() }}" alt="{{ $b->siteName() }}" {{ $attributes->merge(['class' => 'h-9 w-9 rounded-xl object-contain']) }}>
@else
    <svg {{ $attributes->merge(['class' => 'h-9 w-9']) }} viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <defs>
            <linearGradient id="{{ $gid }}" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0" style="stop-color: rgb(var(--brand-400))" />
                <stop offset="1" style="stop-color: rgb(var(--brand-700))" />
            </linearGradient>
        </defs>
        <rect width="40" height="40" rx="11" fill="url(#{{ $gid }})" />
        <path d="M20 9.5c-6.1 0-11 4.3-11 9.7 0 2.9 1.4 5.4 3.6 7.2L11.5 31l5.1-2.3c1.1.3 2.2.4 3.4.4 6.1 0 11-4.3 11-9.7S26.1 9.5 20 9.5Z" fill="#fff" />
        <text x="20" y="22.6" text-anchor="middle" font-family="Figtree, Arial, sans-serif" font-size="8.5" font-weight="800" style="fill: rgb(var(--brand-700))">{{ $initials }}</text>
    </svg>
@endif
