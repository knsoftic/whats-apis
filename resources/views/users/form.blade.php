@php $editing = $user->exists; @endphp
<x-app-layout :title="$editing ? 'Edit user' : 'Add user'">
    <div class="mx-auto max-w-3xl">
        <x-page-header :title="$editing ? 'Edit '.$user->name : 'Add user'" description="Super Admin and Admin can access every WhatsApp number. Agents only see the numbers you assign.">
            <x-button variant="secondary" icon="arrow-left" :href="route('users.index')">Back</x-button>
        </x-page-header>

        <form method="POST" action="{{ $editing ? route('users.update', $user) : route('users.store') }}"
              x-data="{ role: @js(old('role', $currentRole)), saving: false }" @submit="saving = true">
            @csrf
            @if ($editing) @method('PUT') @endif

            <x-card>
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label class="form-label" for="name">Name</label>
                        <input id="name" name="name" class="form-input" value="{{ old('name', $user->name) }}" required maxlength="120">
                        @error('name')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="form-label" for="email">Email</label>
                        <input id="email" type="email" name="email" class="form-input" value="{{ old('email', $user->email) }}" required autocomplete="off">
                        @error('email')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="form-label" for="password">Password {{ $editing ? '(leave blank to keep)' : '' }}</label>
                        <input id="password" type="password" name="password" class="form-input" autocomplete="new-password" @required(! $editing)>
                        @error('password')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="form-label" for="password_confirmation">Confirm password</label>
                        <input id="password_confirmation" type="password" name="password_confirmation" class="form-input" autocomplete="new-password">
                    </div>
                    <div>
                        <label class="form-label" for="role">Role</label>
                        <select id="role" name="role" class="form-input" x-model="role">
                            @foreach ($roles as $r)
                                <option value="{{ $r }}">{{ $r }}</option>
                            @endforeach
                        </select>
                        @error('role')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="flex items-end">
                        <label class="flex items-center gap-2 text-sm">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800" @checked(old('is_active', $user->is_active))>
                            Active (can sign in)
                        </label>
                    </div>
                </div>

                <div class="mt-6 grid gap-5 border-t border-slate-100 pt-5 dark:border-slate-800 sm:grid-cols-2">
                    <div>
                        <label class="form-label" for="company_name">Business name</label>
                        <input id="company_name" name="company_name" class="form-input" value="{{ old('company_name', $user->company_name) }}" maxlength="150">
                    </div>
                    <div>
                        <label class="form-label" for="phone">Phone</label>
                        <input id="phone" name="phone" class="form-input" value="{{ old('phone', $user->phone) }}" maxlength="32">
                    </div>
                    <div>
                        <label class="form-label" for="plan_id">Plan</label>
                        <select id="plan_id" name="plan_id" class="form-input">
                            <option value="">— No plan —</option>
                            @foreach ($plans as $p)
                                <option value="{{ $p->id }}" @selected((int) old('plan_id', $user->plan_id) === $p->id)>{{ $p->name }}{{ $p->is_active ? '' : ' (inactive)' }}</option>
                            @endforeach
                        </select>
                        <p class="form-help" x-show="role === 'Customer'">Limits apply to the numbers this customer owns.</p>
                        <p class="form-help" x-show="role !== 'Customer'" x-cloak>Plans only limit Customers; staff roles are unlimited.</p>
                        @error('plan_id')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="form-label" for="plan_expires_at">Plan valid until</label>
                        <input id="plan_expires_at" name="plan_expires_at" type="date" class="form-input" value="{{ old('plan_expires_at', $user->plan_expires_at?->format('Y-m-d')) }}">
                        <p class="form-help">Empty = no expiry.</p>
                        @error('plan_expires_at')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="mt-6 border-t border-slate-100 pt-5 dark:border-slate-800">
                    <p class="text-sm font-semibold text-slate-900 dark:text-white">WhatsApp numbers this user can access</p>
                    <p class="form-help" x-show="role !== 'Agent'">This role already has access to every number. Assignments below only matter for Agents.</p>
                    @if ($accounts->isEmpty())
                        <p class="mt-3 text-sm text-slate-500">No WhatsApp accounts added yet.</p>
                    @else
                        <div class="mt-3 grid gap-2 sm:grid-cols-2">
                            @foreach ($accounts as $account)
                                <label class="flex items-center gap-3 rounded-xl border border-slate-200 px-3 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">
                                    <input type="checkbox" name="account_ids[]" value="{{ $account->id }}" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800"
                                        @checked(in_array($account->id, old('account_ids', $selectedAccounts)))>
                                    <span class="min-w-0">
                                        <span class="block truncate font-medium">{{ $account->name }}</span>
                                        <span class="block truncate text-xs text-slate-500">{{ $account->display_phone_number ?? '—' }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    @endif
                    @error('account_ids')<p class="form-error">{{ $message }}</p>@enderror
                    @error('account_ids.*')<p class="form-error">{{ $message }}</p>@enderror
                </div>
            </x-card>

            <div class="mt-6 flex justify-end gap-2">
                <x-button variant="secondary" :href="route('users.index')">Cancel</x-button>
                <x-button ::disabled="saving"><span x-show="saving" class="spinner" x-cloak></span>{{ $editing ? 'Save changes' : 'Create user' }}</x-button>
            </div>
        </form>
    </div>
</x-app-layout>
