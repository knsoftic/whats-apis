<x-guest-layout>
    <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-white">Sign in</h1>
    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Use the account created by your administrator.</p>

    <x-auth-session-status class="mt-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" class="mt-8 space-y-5" x-data="{ loading: false }" @submit="loading = true">
        @csrf

        <div>
            <label for="email" class="form-label">Email</label>
            <input id="email" class="form-input" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div>
            <div class="flex items-center justify-between">
                <label for="password" class="form-label">Password</label>
                @if (Route::has('password.request'))
                    <a class="mb-1.5 text-xs font-medium text-emerald-600 hover:text-emerald-700 dark:text-emerald-400" href="{{ route('password.request') }}">Forgot password?</a>
                @endif
            </div>
            <input id="password" class="form-input" type="password" name="password" required autocomplete="current-password">
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <label for="remember_me" class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-400">
            <input id="remember_me" type="checkbox" class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500 dark:border-slate-600 dark:bg-slate-800" name="remember">
            Remember me
        </label>

        <button type="submit" :disabled="loading" class="flex w-full items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700 disabled:opacity-60">
            <span x-show="loading" class="spinner" x-cloak></span>
            Log in
        </button>
    </form>
</x-guest-layout>
