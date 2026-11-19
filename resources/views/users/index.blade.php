<x-app-layout title="Users">
    <x-page-header title="Users" description="Who can sign in, their role, and which WhatsApp numbers they can use.">
        <x-button :href="route('users.create')" icon="plus">Add user</x-button>
    </x-page-header>

    <x-card :padding="false">
        <form method="GET" class="flex gap-2 border-b border-slate-100 p-4 dark:border-slate-800">
            <div class="relative flex-1 sm:max-w-xs">
                <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input type="search" name="q" value="{{ $search }}" placeholder="Search name or email" class="form-input pl-9">
            </div>
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
                        <th>Role</th>
                        <th>WhatsApp numbers</th>
                        <th>Status</th>
                        <th>Last login</th>
                        <th class="text-right">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($users as $user)
                        @php $role = $user->roleName(); @endphp
                        <tr>
                            <td>
                                <p class="font-medium text-slate-900 dark:text-white">{{ $user->name }}</p>
                                <p class="text-xs text-slate-500">{{ $user->email }}</p>
                            </td>
                            <td>
                                <x-badge :color="match ($role) { 'Super Admin' => 'violet', 'Admin' => 'blue', default => 'slate' }">{{ $role }}</x-badge>
                            </td>
                            <td class="max-w-xs text-xs text-slate-600 dark:text-slate-400">
                                @if ($user->hasAnyRole(['Super Admin', 'Admin']))
                                    <span class="italic">All numbers</span>
                                @else
                                    {{ $user->whatsappAccounts->pluck('name')->join(', ') ?: '— none assigned —' }}
                                @endif
                            </td>
                            <td>
                                @if ($user->is_active)
                                    <x-badge color="green" dot>Active</x-badge>
                                @else
                                    <x-badge color="slate" dot>Inactive</x-badge>
                                @endif
                            </td>
                            <td class="whitespace-nowrap text-xs text-slate-500">{{ $user->last_login_at?->diffForHumans() ?? 'Never' }}</td>
                            <td class="text-right">
                                <div class="flex justify-end gap-1">
                                    <x-button variant="ghost" size="sm" icon="pencil" :href="route('users.edit', $user)">Edit</x-button>
                                    @unless (auth()->user()->is($user))
                                        <form method="POST" action="{{ route('users.destroy', $user) }}" onsubmit="return confirm('Delete {{ addslashes($user->name) }}? This cannot be undone.')">
                                            @csrf @method('DELETE')
                                            <x-button variant="ghost" size="sm" icon="trash" class="text-rose-600 dark:text-rose-400">Delete</x-button>
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
</x-app-layout>
