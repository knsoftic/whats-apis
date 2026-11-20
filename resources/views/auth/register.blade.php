<x-guest-layout>
    <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-white">Create your account</h1>
    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
        Connect your WhatsApp Business number in minutes.
        @if ($plan)
            You start on the <strong>{{ $plan->name }}</strong> plan{{ $plan->trial_days ? ' ('.$plan->trial_days.'-day trial)' : '' }}.
        @endif
    </p>

    <form method="POST" action="{{ route('register') }}" class="mt-8 space-y-4" x-data="{ loading: false }" @submit="loading = true">
        @csrf
        <div>
            <label for="name" class="form-label">Your name</label>
            <input id="name" name="name" class="form-input" value="{{ old('name') }}" required autofocus autocomplete="name" maxlength="120">
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>
        <div>
            <label for="company_name" class="form-label">Business name <span class="font-normal text-slate-400">(optional)</span></label>
            <input id="company_name" name="company_name" class="form-input" value="{{ old('company_name') }}" autocomplete="organization" maxlength="150">
            <x-input-error :messages="$errors->get('company_name')" class="mt-2" />
        </div>
        <div>
            <label for="email" class="form-label">Email</label>
            <input id="email" type="email" name="email" class="form-input" value="{{ old('email') }}" required autocomplete="username">
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>
        <div>
            <label for="phone" class="form-label">Phone <span class="font-normal text-slate-400">(optional)</span></label>
            <input id="phone" name="phone" class="form-input" value="{{ old('phone') }}" placeholder="+91 98765 43210" autocomplete="tel">
            <x-input-error :messages="$errors->get('phone')" class="mt-2" />
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="password" class="form-label">Password</label>
                <input id="password" type="password" name="password" class="form-input" required autocomplete="new-password">
            </div>
            <div>
                <label for="password_confirmation" class="form-label">Confirm password</label>
                <input id="password_confirmation" type="password" name="password_confirmation" class="form-input" required autocomplete="new-password">
            </div>
        </div>
        <x-input-error :messages="$errors->get('password')" class="mt-2" />

        <label class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-400">
            <input type="checkbox" name="terms" value="1" class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800" @checked(old('terms'))>
            <span>I agree to the <a href="{{ route('legal.terms') }}" target="_blank" class="font-medium text-brand-600 hover:underline">Terms &amp; Conditions</a> and <a href="{{ route('legal.privacy') }}" target="_blank" class="font-medium text-brand-600 hover:underline">Privacy Policy</a>.</span>
        </label>
        <x-input-error :messages="$errors->get('terms')" class="mt-1" />

        <button type="submit" :disabled="loading" class="flex w-full items-center justify-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-brand-700 disabled:opacity-60">
            <span x-show="loading" class="spinner" x-cloak></span>
            Create account
        </button>
    </form>

    <p class="mt-6 text-center text-sm text-slate-500">Already have an account? <a href="{{ route('login') }}" class="font-medium text-brand-600 hover:underline dark:text-brand-400">Sign in</a></p>
</x-guest-layout>
