<x-app-layout title="Settings">
    <div class="mx-auto max-w-4xl">
        @include('settings._tabs')

        <form method="POST" action="{{ route('settings.update', 'legal') }}" x-data="{ saving: false }" @submit="saving = true">
            @csrf @method('PUT')
            <x-card title="Privacy Policy & Terms" subtitle="These details appear on the public legal pages. Empty fields fall back to the website name / .env.">
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label class="form-label" for="legal_company">Company / legal name</label>
                        <input id="legal_company" name="legal_company" class="form-input" value="{{ old('legal_company', $s->get('legal.company')) }}" placeholder="{{ config('legal.company') }}">
                    </div>
                    <div>
                        <label class="form-label" for="legal_email">Contact email</label>
                        <input id="legal_email" type="email" name="legal_email" class="form-input" value="{{ old('legal_email', $s->get('legal.email')) }}" placeholder="{{ config('legal.email') }}">
                        @error('legal_email')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="form-label" for="legal_jurisdiction">Governing law / country</label>
                        <input id="legal_jurisdiction" name="legal_jurisdiction" class="form-input" value="{{ old('legal_jurisdiction', $s->get('legal.jurisdiction')) }}" placeholder="{{ config('legal.jurisdiction') }}">
                    </div>
                    <div>
                        <label class="form-label" for="legal_address">Postal address (optional)</label>
                        <input id="legal_address" name="legal_address" class="form-input" value="{{ old('legal_address', $s->get('legal.address')) }}">
                    </div>
                </div>
                <div class="mt-4 flex gap-4 text-sm">
                    <a href="{{ route('legal.privacy') }}" target="_blank" class="font-medium text-brand-600 hover:underline">View Privacy Policy ↗</a>
                    <a href="{{ route('legal.terms') }}" target="_blank" class="font-medium text-brand-600 hover:underline">View Terms ↗</a>
                </div>
            </x-card>
            <div class="mt-6 flex justify-end"><x-button ::disabled="saving"><span x-show="saving" class="spinner" x-cloak></span>Save legal settings</x-button></div>
        </form>
    </div>
</x-app-layout>
