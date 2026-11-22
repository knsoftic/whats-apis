<x-app-layout title="Users">
    <x-page-header title="Users" description="Who can sign in, their role and plan — log in as a customer to review their account, or suspend / block it.">
        <x-button :href="route('users.create')" icon="plus">Add user</x-button>
    </x-page-header>

    <div x-data="{ modal: null, status: 'suspended', open(u, s) { this.modal = u; this.status = s; } }">
    <x-card :padding="false">
        <form method="GET" class="flex flex-wrap items-center gap-2 border-b border-slate-100 p-4 dark:border-slate-800">
            <div class="relative flex-1 sm:max-w-xs">
                <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input type="search" name="q" value="{{ $search }}" placeholder="Search name or email" class="form-input pl-9">
            </div>
            <select name="status" class="form-input w-auto" onchange="this.form.submit()" aria-label="Filter by status">
                <option value="">All statuses</option>
                @foreach (['active' => 'Active', 'suspended' => 'Suspended', 'blocked' => 'Blocked'] as $value => $label)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <x-button variant="secondary">Search</x-button>
        </form>

        @if ($users->isEmpty())
            <x-empty-state icon="users" title="No users found" />
        @else
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead>
                    <tr>
                        <th>User</th>
                        <th>Role &amp; plan</th>
                        <th>WhatsApp numbers</th>
                        <th>Status</th>
                        <th class="text-right">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($users as $user)
                        @php
                            $role = $user->roleName();
                            $canImpersonate = \App\Support\Impersonation::canImpersonate(auth()->user(), $user);
                            $canChangeStatus = ! auth()->user()->is($user) && ! $user->hasRole('Super Admin');
                        @endphp
                        <tr @class(['bg-rose-50/40 dark:bg-rose-500/5' => $user->isBlocked(), 'bg-amber-50/40 dark:bg-amber-500/5' => $user->isSuspended()])>
                            <td>
                                <p class="font-medium text-slate-900 dark:text-white">{{ $user->name }}</p>
                                <p class="text-xs text-slate-500">{{ $user->email }}</p>
                                <p class="text-[11px] text-slate-400">Last login {{ $user->last_login_at?->diffForHumans() ?? 'never' }}</p>
                            </td>
                            <td>
                                <x-badge :color="match ($role) { 'Super Admin' => 'violet', 'Admin' => 'blue', 'Customer' => 'green', default => 'slate' }">{{ $role }}</x-badge>
                                @if ($user->company_name)<p class="mt-1 text-xs text-slate-500">{{ $user->company_name }}</p>@endif
                                <p class="mt-1 whitespace-nowrap text-xs">{{ $user->plan?->name ? 'Plan: '.$user->plan->name : '' }}
                                @if ($user->plan_expires_at)
                                    <span @class(['block', 'text-rose-600' => $user->plan_expires_at->isPast(), 'text-slate-500' => ! $user->plan_expires_at->isPast()])>{{ $user->plan_expires_at->isPast() ? 'expired' : 'until' }} {{ $user->plan_expires_at->toFormattedDateString() }}</span>
                                @endif
                                </p>
                            </td>
                            <td class="max-w-[14rem] text-xs text-slate-600 dark:text-slate-400">
                                @if ($user->hasAnyRole(['Super Admin', 'Admin']))
                                    <span class="italic">All numbers</span>
                                @elseif ($user->hasRole('Customer'))
                                    <span class="font-medium text-slate-800 dark:text-slate-200">{{ $user->owned_accounts_count }} / {{ $user->plan?->max_whatsapp_accounts ?? '∞' }}</span> owned
                                    @if ($user->whatsappAccounts->isNotEmpty())<span class="block truncate">{{ $user->whatsappAccounts->pluck('name')->join(', ') }}</span>@endif
                                @else
                                    {{ $user->whatsappAccounts->pluck('name')->join(', ') ?: '— none assigned —' }}
                                @endif
                            </td>
                            <td>
                                @if ($user->isBlocked())
                                    <x-badge color="red" dot>Blocked</x-badge>
                                @elseif ($user->isSuspended())
                                    <x-badge color="amber" dot>Suspended</x-badge>
                                @elseif ($user->is_active)
                                    <x-badge color="green" dot>Active</x-badge>
                                @else
                                    <x-badge color="slate" dot>Inactive</x-badge>
                                @endif
                                @if ($user->status_reason)<p class="mt-1 max-w-[10rem] truncate text-xs text-slate-500" title="{{ $user->status_reason }}">{{ $user->status_reason }}</p>@endif
                            </td>
                            <td class="text-right">
                                <div class="ml-auto flex max-w-[15rem] flex-wrap justify-end gap-1">
                                    @if ($canImpersonate)
                                        <form method="POST" action="{{ route('users.impersonate', $user) }}">
                                            @csrf
                                            <x-button variant="secondary" size="sm" icon="eye" title="See the app exactly as this user">Login as</x-button>
                                        </form>
                                    @endif
                                    @if ($canChangeStatus)
                                        @if ($user->isInGoodStanding())
                                            <button type="button" class="inline-flex items-center gap-1 rounded-xl px-2.5 py-1.5 text-xs font-medium text-amber-700 hover:bg-amber-50 dark:text-amber-400 dark:hover:bg-amber-500/10"
                                                    @click="open({{ \Illuminate\Support\Js::from(['name' => $user->name, 'url' => route('users.status', $user)]) }}, 'suspended')">
                                                <x-icon name="lock" class="h-4 w-4" /> Suspend / Block
                                            </button>
                                        @else
                                            <form method="POST" action="{{ route('users.status', $user) }}" onsubmit="return confirm('Re-activate {{ addslashes($user->name) }}?')">
                                                @csrf
                                                <input type="hidden" name="status" value="active">
                                                <x-button variant="ghost" size="sm" icon="check" class="text-emerald-700 dark:text-emerald-400">Re-activate</x-button>
                                            </form>
                                        @endif
                                    @endif
                                    <x-button variant="ghost" size="sm" icon="pencil" :href="route('users.edit', $user)" title="Edit" aria-label="Edit {{ $user->name }}"></x-button>
                                    @unless (auth()->user()->is($user))
                                        <form method="POST" action="{{ route('users.destroy', $user) }}" onsubmit="return confirm('Delete {{ addslashes($user->name) }}? This cannot be undone.')">
                                            @csrf @method('DELETE')
                                            <x-button variant="ghost" size="sm" icon="trash" class="text-rose-600 dark:text-rose-400" title="Delete" aria-label="Delete {{ $user->name }}"></x-button>
                                        </form>
                                    @endunless
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-100 p-4 dark:border-slate-800">{{ $users->links() }}</div>
        @endif
    </x-card>

    {{-- Suspend / block dialog --}}
    <div x-show="modal" x-cloak class="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/50 sm:items-center sm:p-4" @keydown.escape.window="modal = null">
        <form method="POST" :action="modal?.url" @click.outside="modal = null" class="w-full max-w-md rounded-t-2xl bg-white p-6 shadow-xl dark:bg-slate-900 sm:rounded-2xl">
            @csrf
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Suspend or block <span x-text="modal?.name"></span></h2>
            <div class="mt-4 space-y-2">
                <label class="flex cursor-pointer gap-3 rounded-xl border p-3" :class="status === 'suspended' ? 'border-amber-400 bg-amber-50 dark:bg-amber-500/10' : 'border-slate-200 dark:border-slate-700'">
                    <input type="radio" name="status" value="suspended" x-model="status" class="mt-0.5 text-amber-600 focus:ring-amber-500">
                    <span><span class="block text-sm font-medium">Suspend</span><span class="text-xs text-slate-500">Can still sign in but only sees a “suspended” notice with your reason. Their WhatsApp numbers stop sending and bots pause.</span></span>
                </label>
                <label class="flex cursor-pointer gap-3 rounded-xl border p-3" :class="status === 'blocked' ? 'border-rose-400 bg-rose-50 dark:bg-rose-500/10' : 'border-slate-200 dark:border-slate-700'">
                    <input type="radio" name="status" value="blocked" x-model="status" class="mt-0.5 text-rose-600 focus:ring-rose-500">
                    <span><span class="block text-sm font-medium">Block</span><span class="text-xs text-slate-500">Cannot sign in at all and is signed out now. Their numbers stop sending too.</span></span>
                </label>
            </div>
            <label class="form-label mt-4" for="status_reason">Reason <span class="font-normal text-slate-400">(shown to a suspended user)</span></label>
            <textarea id="status_reason" name="reason" rows="3" maxlength="500" class="form-input" placeholder="e.g. Payment pending — please renew your plan."></textarea>
            <p class="form-help">Nothing is deleted. You can re-activate the account any time.</p>
            <div class="mt-6 flex justify-end gap-2">
                <x-button type="button" variant="secondary" x-on:click="modal = null">Cancel</x-button>
                <x-button variant="danger" x-text="status === 'blocked' ? 'Block user' : 'Suspend user'">Suspend user</x-button>
            </div>
        </form>
    </div>
    </div>
</x-app-layout>
