<x-app-layout title="Settings">
    <div class="mx-auto max-w-4xl">
        @include('settings._tabs')

        <form method="POST" action="{{ route('settings.update', 'general') }}" enctype="multipart/form-data" x-data="{ saving: false }" @submit="saving = true">
            @csrf @method('PUT')
            <x-card title="Website">
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label class="form-label" for="site_name">Website / app name</label>
                        <input id="site_name" name="site_name" class="form-input" value="{{ old('site_name', $s->siteName()) }}" required maxlength="60">
                        <p class="form-help">Shown in the sidebar, login page, browser title and emails.</p>
                        @error('site_name')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="form-label" for="site_tagline">Tagline</label>
                        <input id="site_tagline" name="site_tagline" class="form-input" value="{{ old('site_tagline', $s->tagline()) }}" maxlength="80">
                    </div>
                    <div>
                        <label class="form-label" for="support_email">Support email</label>
                        <input id="support_email" type="email" name="support_email" class="form-input" value="{{ old('support_email', $s->get('site.support_email')) }}">
                        @error('support_email')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="form-label" for="support_phone">Support phone / WhatsApp</label>
                        <input id="support_phone" name="support_phone" class="form-input" value="{{ old('support_phone', $s->get('site.support_phone')) }}">
                    </div>
                </div>
            </x-card>

            <x-card class="mt-6" title="Logo" subtitle="PNG, JPG or WebP, max 1 MB. Square works best.">
                <div class="flex flex-wrap items-center gap-5">
                    @if ($s->logoUrl())
                        <img src="{{ $s->logoUrl() }}" alt="Logo" class="h-16 w-16 rounded-xl object-contain ring-1 ring-slate-200 dark:ring-slate-700">
                    @else
                        <x-application-logo class="h-16 w-16" />
                    @endif
                    <div class="space-y-2">
                        <input type="file" name="logo" accept=".png,.jpg,.jpeg,.webp" class="block text-sm file:mr-3 file:rounded-xl file:border-0 file:bg-brand-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-brand-700 dark:file:bg-brand-500/10 dark:file:text-brand-300">
                        @if ($s->logoUrl())
                            <label class="flex items-center gap-2 text-xs text-slate-500"><input type="checkbox" name="remove_logo" value="1" class="rounded border-slate-300"> Remove logo (use default)</label>
                        @endif
                        @error('logo')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                </div>
            </x-card>

            <x-card class="mt-6" title="Registration">
                <label class="flex items-center gap-3 text-sm">
                    <input type="hidden" name="registration_enabled" value="0">
                    <input type="checkbox" name="registration_enabled" value="1" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800" @checked($s->bool('platform.registration_enabled'))>
                    <span><span class="font-medium">Allow public sign-up</span> at <code>{{ route('register') }}</code> — new users get the default plan (Plans page).</span>
                </label>
            </x-card>

            <div class="mt-6 flex justify-end"><x-button ::disabled="saving"><span x-show="saving" class="spinner" x-cloak></span>Save general settings</x-button></div>
        </form>
    </div>
</x-app-layout>
