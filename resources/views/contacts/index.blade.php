@php use App\Support\Permissions as P; @endphp
<x-app-layout title="Contacts">
    <x-page-header title="Contacts" description="Customers of your WhatsApp numbers. Created automatically when someone messages you.">
        @can(P::CONTACTS_IMPORT_EXPORT)
            <x-button variant="secondary" icon="upload" :href="route('contacts.import.form')">Import CSV</x-button>
            <x-button variant="secondary" icon="download" :href="route('contacts.export', request()->query())">Export CSV</x-button>
        @endcan
        @can(P::CONTACTS_MANAGE)
            <x-button icon="plus" :href="route('contacts.create')">Add contact</x-button>
        @endcan
    </x-page-header>

    <x-card :padding="false">
        <form method="GET" class="grid gap-2 border-b border-slate-100 p-4 dark:border-slate-800 sm:grid-cols-2 lg:grid-cols-5">
            <div class="relative lg:col-span-2">
                <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Name, number or email" class="form-input pl-9">
            </div>
            <select name="account_id" class="form-input">
                <option value="">All my WhatsApp numbers</option>
                @foreach ($accounts as $a)
                    <option value="{{ $a->id }}" @selected((int) ($filters['account_id'] ?? 0) === $a->id)>{{ $a->name }}</option>
                @endforeach
            </select>
            <select name="tag" class="form-input">
                <option value="">Any tag</option>
                @foreach ($tags as $t)
                    <option value="{{ $t->id }}" @selected((int) ($filters['tag'] ?? 0) === $t->id)>{{ $t->name }}</option>
                @endforeach
            </select>
            <div class="flex gap-2">
                <select name="opt_in" class="form-input">
                    <option value="">Any consent</option>
                    <option value="opted_in" @selected(($filters['opt_in'] ?? '') === 'opted_in')>Opted in</option>
                    <option value="opted_out" @selected(($filters['opt_in'] ?? '') === 'opted_out')>Opted out</option>
                    <option value="unknown" @selected(($filters['opt_in'] ?? '') === 'unknown')>Unknown</option>
                </select>
                <x-button variant="secondary" icon="filter" aria-label="Filter"></x-button>
            </div>
        </form>

        @if ($contacts->isEmpty())
            <x-empty-state icon="contacts" title="No contacts found" description="Contacts are created automatically when customers message your number, or you can add / import them." />
        @else
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th>Contact</th><th>Number</th><th>Tags</th><th>Consent</th><th>WhatsApp number</th><th>Last message</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($contacts as $contact)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                            <td>
                                <a href="{{ route('contacts.show', $contact) }}" class="flex items-center gap-3">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-300">{{ $contact->initials() }}</span>
                                    <span class="min-w-0">
                                        <span class="block truncate font-medium text-slate-900 hover:text-brand-700 dark:text-white">{{ $contact->displayName() }}</span>
                                        @if ($contact->profile_name && $contact->profile_name !== $contact->name)
                                            <span class="block truncate text-xs text-slate-500">~{{ $contact->profile_name }}</span>
                                        @endif
                                    </span>
                                </a>
                            </td>
                            <td class="whitespace-nowrap font-mono text-xs">{{ $contact->phone }}</td>
                            <td><div class="flex max-w-[220px] flex-wrap gap-1">@foreach ($contact->tags as $tag)<x-badge color="blue">{{ $tag->name }}</x-badge>@endforeach</div></td>
                            <td>
                                @switch($contact->opt_in_status)
                                    @case('opted_in') <x-badge color="green" dot>Opted in</x-badge> @break
                                    @case('opted_out') <x-badge color="red" dot>Opted out</x-badge> @break
                                    @default <x-badge color="slate">Unknown</x-badge>
                                @endswitch
                            </td>
                            <td class="whitespace-nowrap text-xs">{{ $contact->account->name }}</td>
                            <td class="whitespace-nowrap text-xs text-slate-500">
                                {{ $contact->conversation?->last_message_at?->diffForHumans() ?? '—' }}
                                @if ($contact->conversation?->unread_count) <x-badge color="green">{{ $contact->conversation->unread_count }} new</x-badge> @endif
                            </td>
                            <td class="text-right">
                                @if ($contact->conversation)
                                    <x-button size="sm" variant="ghost" icon="chat" :href="route('chat.index', ['conversation' => $contact->conversation->id])">Chat</x-button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-100 p-4 dark:border-slate-800">{{ $contacts->links() }}</div>
        @endif
    </x-card>
</x-app-layout>
