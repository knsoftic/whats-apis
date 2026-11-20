<x-app-layout title="Settings">
    <div class="mx-auto max-w-4xl">
        @include('settings._tabs')

        <form method="POST" action="{{ route('settings.update', 'appearance') }}" x-data="{ saving: false, color: @js(old('brand_color', $s->brandColor())) }" @submit="saving = true">
            @csrf @method('PUT')
            <x-card title="Brand colour" subtitle="Buttons, links, active menu items and highlights. Status colours (green = connected, red = failed) stay the same.">
                <div class="grid grid-cols-3 gap-3 sm:grid-cols-6">
                    @foreach (\App\Support\PlatformSettings::PALETTES as $name => $palette)
                        <label class="cursor-pointer">
                            <input type="radio" name="brand_color" value="{{ $name }}" x-model="color" class="peer sr-only">
                            <span class="flex flex-col items-center gap-2 rounded-2xl border-2 p-3 text-xs capitalize transition peer-checked:border-slate-900 dark:peer-checked:border-white"
                                  :class="color === @js($name) ? '' : 'border-transparent hover:border-slate-200 dark:hover:border-slate-700'">
                                <span class="flex h-9 w-9 items-center justify-center rounded-full" style="background: rgb({{ $palette[6] }})">
                                    <x-icon name="check" class="h-5 w-5 text-white" x-show="color === '{{ $name }}'" />
                                </span>
                                {{ $name }}
                            </span>
                        </label>
                    @endforeach
                </div>
                <p class="form-help mt-3">The new colour shows after saving.</p>
            </x-card>

            <x-card class="mt-6" title="Theme">
                <div class="grid gap-3 sm:grid-cols-3">
                    @foreach (['system' => ['Follow device', 'Light or dark, as the visitor\'s device prefers'], 'light' => ['Light', 'Always start in light mode'], 'dark' => ['Dark', 'Always start in dark mode']] as $value => [$label, $help])
                        <label class="flex cursor-pointer items-start gap-3 rounded-2xl border border-slate-200 p-4 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">
                            <input type="radio" name="default_theme" value="{{ $value }}" class="mt-1 text-brand-600 focus:ring-brand-500" @checked(old('default_theme', $s->defaultTheme()) === $value)>
                            <span><span class="block text-sm font-medium">{{ $label }}</span><span class="text-xs text-slate-500">{{ $help }}</span></span>
                        </label>
                    @endforeach
                </div>
                <label class="mt-4 flex items-center gap-3 text-sm">
                    <input type="hidden" name="allow_toggle" value="0">
                    <input type="checkbox" name="allow_toggle" value="1" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800" @checked($s->bool('theme.allow_toggle'))>
                    Let users switch light/dark themselves (moon/sun button)
                </label>
            </x-card>

            <div class="mt-6 flex justify-end"><x-button ::disabled="saving"><span x-show="saving" class="spinner" x-cloak></span>Save appearance</x-button></div>
        </form>
    </div>
</x-app-layout>
