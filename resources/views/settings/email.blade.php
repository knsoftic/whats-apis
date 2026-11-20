<x-app-layout title="Settings">
    <div class="mx-auto max-w-4xl">
        @include('settings._tabs')

        <form method="POST" action="{{ route('settings.update', 'email') }}" x-data="{ saving: false, on: @js($s->bool('mail.enabled')) }" @submit="saving = true" autocomplete="off">
            @csrf @method('PUT')
            <x-card title="Outgoing email (SMTP)" subtitle="Used for password-reset emails and notifications. When off, the server's .env mail settings are used.">
                <label class="flex items-center gap-3 text-sm">
                    <input type="hidden" name="mail_enabled" value="0">
                    <input type="checkbox" name="mail_enabled" value="1" x-model="on" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800">
                    <span class="font-medium">Use these SMTP settings</span>
                </label>
                <div class="mt-4 grid gap-5 sm:grid-cols-2" :class="on ? '' : 'opacity-60'">
                    <div>
                        <label class="form-label" for="mail_host">SMTP host</label>
                        <input id="mail_host" name="mail_host" class="form-input" value="{{ old('mail_host', $s->get('mail.host')) }}" placeholder="smtp.gmail.com">
                        @error('mail_host')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="form-label" for="mail_port">Port</label>
                            <input id="mail_port" name="mail_port" type="number" class="form-input" value="{{ old('mail_port', $s->get('mail.port')) }}">
                        </div>
                        <div>
                            <label class="form-label" for="mail_encryption">Encryption</label>
                            <select id="mail_encryption" name="mail_encryption" class="form-input">
                                @foreach (['tls' => 'TLS (587)', 'ssl' => 'SSL (465)', 'none' => 'None'] as $v => $l)
                                    <option value="{{ $v }}" @selected(old('mail_encryption', $s->get('mail.encryption')) === $v)>{{ $l }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="form-label" for="mail_username">Username</label>
                        <input id="mail_username" name="mail_username" class="form-input" value="{{ old('mail_username', $s->get('mail.username')) }}" autocomplete="off">
                    </div>
                    <div>
                        <label class="form-label" for="mail_password">Password</label>
                        @if ($s->get('mail.password'))<p class="mb-1 text-xs text-slate-500">Saved — leave blank to keep.</p>@endif
                        <input id="mail_password" name="mail_password" type="password" class="form-input" autocomplete="new-password">
                    </div>
                    <div>
                        <label class="form-label" for="mail_from_address">From address</label>
                        <input id="mail_from_address" name="mail_from_address" type="email" class="form-input" value="{{ old('mail_from_address', $s->get('mail.from_address')) }}" placeholder="no-reply@whats-apis.com">
                        @error('mail_from_address')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="form-label" for="mail_from_name">From name</label>
                        <input id="mail_from_name" name="mail_from_name" class="form-input" value="{{ old('mail_from_name', $s->get('mail.from_name')) }}" placeholder="{{ $s->siteName() }}">
                    </div>
                </div>
            </x-card>
            <div class="mt-6 flex justify-end"><x-button ::disabled="saving"><span x-show="saving" class="spinner" x-cloak></span>Save email settings</x-button></div>
        </form>

        <form method="POST" action="{{ route('settings.email.test') }}" class="mt-3 flex justify-end">
            @csrf
            <x-button variant="secondary" icon="envelope">Send test email to me</x-button>
        </form>
    </div>
</x-app-layout>
