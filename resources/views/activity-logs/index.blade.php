<x-app-layout title="Activity logs">
    <x-page-header title="Activity logs" description="Audit trail of sign-ins and changes. Secrets are never stored here." />

    <x-card :padding="false">
        <form method="GET" class="grid gap-2 border-b border-slate-100 p-4 dark:border-slate-800 sm:grid-cols-4">
            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search description" class="form-input">
            <select name="action" class="form-input">
                <option value="">All actions</option>
                @foreach ($actionGroups as $key => $label)
                    <option value="{{ $key }}" @selected(($filters['action'] ?? '') === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <select name="user_id" class="form-input">
                <option value="">All users</option>
                @foreach ($users as $u)
                    <option value="{{ $u->id }}" @selected((int) ($filters['user_id'] ?? 0) === $u->id)>{{ $u->name }}</option>
                @endforeach
            </select>
            <x-button variant="secondary" icon="filter">Filter</x-button>
        </form>

        @if ($logs->isEmpty())
            <x-empty-state icon="activity" title="No activity recorded" />
        @else
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead>
                    <tr><th>When</th><th>User</th><th>Action</th><th>Description</th><th>WhatsApp number</th><th>IP</th></tr>
                    </thead>
                    <tbody>
                    @foreach ($logs as $log)
                        <tr>
                            <td class="whitespace-nowrap text-xs text-slate-500" title="{{ $log->created_at }}">{{ $log->created_at?->diffForHumans() }}</td>
                            <td class="whitespace-nowrap">{{ $log->user?->name ?? 'System' }}</td>
                            <td><code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs dark:bg-slate-800">{{ $log->action }}</code></td>
                            <td class="min-w-[240px]">{{ $log->description }}</td>
                            <td class="whitespace-nowrap text-xs">{{ $log->account?->name ?? '—' }}</td>
                            <td class="whitespace-nowrap text-xs text-slate-500">{{ $log->ip_address ?? '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-100 p-4 dark:border-slate-800">{{ $logs->links() }}</div>
        @endif
    </x-card>
</x-app-layout>
