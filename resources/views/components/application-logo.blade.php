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
        {{-- Neutral monogram (no speech bubble) so the default mark is not mistaken for the WhatsApp logo. --}}
        <rect width="40" height="40" rx="11" fill="url(#{{ $gid }})" />
        <rect x="6" y="6" width="28" height="28" rx="8" fill="none" stroke="#fff" stroke-opacity=".35" stroke-width="1.5" />
        <text x="20" y="25" text-anchor="middle" font-family="Figtree, Arial, sans-serif" font-size="13" font-weight="800" fill="#fff">{{ $initials }}</text>
    </svg>
@endif
