<x-guest-layout>
    <div class="text-center">
        <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">
            <x-icon name="lock" class="h-7 w-7" />
        </span>
        <h1 class="mt-5 text-xl font-semibold text-slate-900 dark:text-white">Your account is suspended</h1>
        <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">
            Your WhatsApp numbers are paused — messages and bots are not sent until the account is active again.
        </p>

        @if ($user->status_reason)
            <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-left text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                <p class="text-xs font-semibold uppercase tracking-wide">Reason</p>
                <p class="mt-1 whitespace-pre-line">{{ $user->status_reason }}</p>
            </div>
        @endif

        @php
            $email = $brand->get('site.support_email');
            $phone = $brand->get('site.support_phone');
        @endphp
        @if ($email || $phone)
            <p class="mt-5 text-sm text-slate-600 dark:text-slate-400">Please contact support:</p>
            <p class="mt-1 space-x-3 text-sm font-medium">
                @if ($email)<a href="mailto:{{ $email }}" class="text-brand-700 hover:underline dark:text-brand-400">{{ $email }}</a>@endif
                @if ($phone)<a href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}" class="text-brand-700 hover:underline dark:text-brand-400">{{ $phone }}</a>@endif
            </p>
        @else
            <p class="mt-5 text-sm text-slate-600 dark:text-slate-400">Please contact the administrator.</p>
        @endif

        <form method="POST" action="{{ route('logout') }}" class="mt-8">
            @csrf
            <x-button variant="secondary" class="w-full">Log out</x-button>
        </form>
    </div>
</x-guest-layout>
