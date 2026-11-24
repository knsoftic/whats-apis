<x-app-layout title="Leads">
    <x-page-header title="Leads" description="Every new number that messages you — saved automatically with the WhatsApp profile name and first message. Export it for marketing campaigns.">
        @can(\App\Support\Permissions::CONTACTS_IMPORT_EXPORT)
            <x-button icon="download" :href="route('leads.export', array_filter($filters))">Export to Excel (CSV)</x-button>
        @endcan
    </x-page-header>

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <x-stat-card label="Leads (filtered)" :value="number_format($total)" icon="users" color="emerald" />
        <x-stat-card label="Last 7 days" :value="number_format($week)" icon="chart" color="sky" />
        <x-stat-card label="Today" :value="number_format($today)" icon="bolt" color="amber" />
    </div>

    <x-card :padding="false">
        <form method="GET" class="grid gap-2 border-b border-slate-100 p-4 dark:border-slate-800 sm:grid-cols-2 lg:grid-cols-5">
            <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Name, number or message" class="form-input lg:col-span-2">
            @if ($accounts->count() > 1)
                <select name="account_id" class="form-input" aria-label="WhatsApp number">
                    <option value="">All numbers</option>
                    @foreach ($accounts as $a)
                        <option value="{{ $a->id }}" @selected($filters['account_id'] === $a->id)>{{ $a->name }}</option>
                    @endforeach
                </select>
            @endif
            <div class="flex gap-2 {{ $accounts->count() > 1 ? 'lg:col-span-2' : 'lg:col-span-3' }}">
                <input type="date" name="from" value="{{ $filters['from'] }}" class="form-input" aria-label="From date">
                <input type="date" name="to" value="{{ $filters['to'] }}" class="form-input" aria-label="To date">
                <x-button variant="secondary" icon="filter" aria-label="Filter"></x-button>
            </div>
        </form>

        @if ($leads->isEmpty())
            <x-empty-state icon="users" title="No leads yet" description="When a new number sends its first WhatsApp message, it appears here automatically." />
        @else
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th>Name</th><th>Number</th><th>First message</th><th>Received</th>@if ($accounts->count() > 1)<th>WhatsApp number</th>@endif<th></th></tr></thead>
                    <tbody>
                    @foreach ($leads as $lead)
                        <tr>
                            <td class="font-medium text-slate-900 dark:text-white">{{ $lead->profile_name ?: '—' }}</td>
                            <td class="whitespace-nowrap font-mono text-xs">{{ $lead->phone }}</td>
                            <td class="max-w-sm"><p class="truncate text-sm text-slate-600 dark:text-slate-400" title="{{ $lead->first_message }}">{{ $lead->first_message ?: '['.$lead->first_message_type.']' }}</p></td>
                            <td class="whitespace-nowrap text-xs text-slate-500" title="{{ $lead->first_message_at }}">{{ $lead->first_message_at->format('d M Y, H:i') }}</td>
                            @if ($accounts->count() > 1)<td class="text-xs text-slate-500">{{ $lead->account?->name }}</td>@endif
                            <td class="text-right">
                                @if ($lead->contact_id)
                                    <x-button size="sm" variant="ghost" icon="user" :href="route('contacts.show', $lead->contact_id)" aria-label="Open contact"></x-button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-100 p-4 dark:border-slate-800">{{ $leads->links() }}</div>
        @endif
    </x-card>
    <p class="mt-3 text-xs text-slate-500">Only the first message of each number is saved (one row per number). Before running a marketing campaign, make sure people opted in to receive messages, and use approved templates.</p>
</x-app-layout>
