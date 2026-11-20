@php use App\Support\Permissions as P; @endphp
<x-app-layout :title="$contact->displayName()">
    <x-page-header :title="$contact->displayName()" :description="$contact->phone.' · '.$contact->account->name">
        <x-button variant="secondary" icon="arrow-left" :href="route('contacts.index')">Contacts</x-button>
        @if ($contact->conversation)
            <x-button icon="chat" :href="route('chat.index', ['conversation' => $contact->conversation->id])">Open chat</x-button>
        @endif
        @can('update', $contact)
            <x-button variant="secondary" icon="pencil" :href="route('contacts.edit', $contact)">Edit</x-button>
        @endcan
    </x-page-header>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6">
            <x-card title="Details">
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Name</dt><dd class="font-medium">{{ $contact->name ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">WhatsApp profile</dt><dd>{{ $contact->profile_name ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Number</dt><dd class="font-mono text-xs">{{ $contact->phone }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Email</dt><dd>{{ $contact->email ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Consent</dt><dd>
                        @switch($contact->opt_in_status)
                            @case('opted_in') <x-badge color="green" dot>Opted in</x-badge> @break
                            @case('opted_out') <x-badge color="red" dot>Opted out</x-badge> @break
                            @default <x-badge>Unknown</x-badge>
                        @endswitch
                    </dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Consent source</dt><dd>{{ $contact->opt_in_source ? str_replace('_', ' ', $contact->opt_in_source) : '—' }}</dd></div>
                    @if ($contact->opted_in_at)<div class="flex justify-between gap-3"><dt class="text-slate-500">Opted in at</dt><dd class="text-xs">{{ $contact->opted_in_at->toDayDateTimeString() }}</dd></div>@endif
                    @if ($contact->opted_out_at)<div class="flex justify-between gap-3"><dt class="text-slate-500">Opted out at</dt><dd class="text-xs">{{ $contact->opted_out_at->toDayDateTimeString() }}</dd></div>@endif
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Added</dt><dd class="text-xs">{{ $contact->created_at->toFormattedDateString() }}</dd></div>
                    <div><dt class="mb-1 text-slate-500">Tags</dt><dd class="flex flex-wrap gap-1">
                        @forelse ($contact->tags as $tag) <x-badge color="blue">{{ $tag->name }}</x-badge> @empty <span class="text-slate-400">No tags</span> @endforelse
                    </dd></div>
                </dl>
            </x-card>

            <x-card title="Internal notes" subtitle="Visible to your team only — never sent to the customer.">
                @can('update', $contact)
                    <form method="POST" action="{{ route('contacts.notes.store', $contact) }}" class="mb-4 space-y-2">
                        @csrf
                        <textarea name="body" rows="3" maxlength="2000" class="form-input" placeholder="Add a note…" required></textarea>
                        @error('body')<p class="form-error">{{ $message }}</p>@enderror
                        <div class="flex justify-end"><x-button size="sm" icon="plus">Add note</x-button></div>
                    </form>
                @endcan
                <div class="space-y-3">
                    @forelse ($contact->notes as $note)
                        <div class="rounded-xl bg-amber-50 p-3 text-sm dark:bg-amber-500/10">
                            <p class="whitespace-pre-wrap text-slate-800 dark:text-slate-200">{{ $note->body }}</p>
                            <div class="mt-2 flex items-center justify-between text-xs text-slate-500">
                                <span>{{ $note->user?->name ?? 'Deleted user' }} · {{ $note->created_at->diffForHumans() }}</span>
                                @can('update', $contact)
                                    <form method="POST" action="{{ route('contacts.notes.destroy', [$contact, $note]) }}" onsubmit="return confirm('Delete this note?')">
                                        @csrf @method('DELETE')
                                        <button class="text-rose-600 hover:underline">Delete</button>
                                    </form>
                                @endcan
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">No notes yet.</p>
                    @endforelse
                </div>
            </x-card>

            @can('delete', $contact)
                <x-card class="border-rose-200 dark:border-rose-900">
                    <form method="POST" action="{{ route('contacts.destroy', $contact) }}" onsubmit="return confirm('Delete this contact and its whole conversation history? This cannot be undone.')">
                        @csrf @method('DELETE')
                        <x-button variant="danger" icon="trash" size="sm">Delete contact & history</x-button>
                    </form>
                </x-card>
            @endcan
        </div>

        <x-card title="Message history" class="xl:col-span-2" :padding="false">
            @forelse ($messages as $m)
                <div class="flex gap-3 border-b border-slate-100 px-5 py-3 last:border-0 dark:border-slate-800">
                    <span @class(['mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full',
                        'bg-brand-100 text-brand-600 dark:bg-brand-500/15' => $m->isInbound(),
                        'bg-violet-100 text-violet-600 dark:bg-violet-500/15' => $m->isOutbound()])>
                        <x-icon :name="$m->isInbound() ? 'arrow-down-left' : 'arrow-up-right'" class="h-4 w-4" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="whitespace-pre-wrap break-words text-sm text-slate-800 dark:text-slate-200">{{ $m->type === 'text' ? $m->body : $m->previewText() }}</p>
                        <p class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                            <span>{{ ($m->wa_timestamp ?? $m->created_at)->toDayDateTimeString() }}</span>
                            <x-badge :color="match ($m->status) { 'read' => 'blue', 'delivered', 'sent', 'received' => 'green', 'failed' => 'red', default => 'amber' }">{{ $m->status }}</x-badge>
                            @if ($m->type !== 'text')<x-badge>{{ $m->type }}</x-badge>@endif
                            @foreach ($m->attachments as $a)
                                @if ($a->isAvailable())<a href="{{ route('media.show', ['attachment' => $a, 'download' => 1]) }}" class="text-brand-600 hover:underline">Download {{ $a->type }}</a>@endif
                            @endforeach
                        </p>
                        @if ($m->status === 'failed' && $m->error_message)<p class="mt-1 text-xs text-rose-600">{{ $m->error_message }}</p>@endif
                    </div>
                </div>
            @empty
                <x-empty-state icon="chat" title="No messages yet" />
            @endforelse
            @if ($messages->hasPages())
                <div class="border-t border-slate-100 p-4 dark:border-slate-800">{{ $messages->links() }}</div>
            @endif
        </x-card>
    </div>
</x-app-layout>
