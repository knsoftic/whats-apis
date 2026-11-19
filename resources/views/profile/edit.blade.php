<x-app-layout title="Profile">
    <div class="mx-auto max-w-3xl">
        <x-page-header title="Your profile" description="Update your name, email address and password." />

        <div class="space-y-6">
            <x-card>
                <div class="max-w-xl">
                    @include('profile.partials.update-profile-information-form')
                </div>
            </x-card>

            <x-card>
                <div class="max-w-xl">
                    @include('profile.partials.update-password-form')
                </div>
            </x-card>
        </div>
    </div>
</x-app-layout>
