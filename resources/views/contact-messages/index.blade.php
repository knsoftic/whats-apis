<x-app-layout title="Contact messages">
    <x-page-header title="Contact messages" description="Messages sent from the contact form on your landing page.">
        @if ($unread)
            <form method="POST" action="{{ route('contact-messages.read-all') }}">
                @csrf
                <x-button variant="secondary" icon="check">Mark all read</x-button>
            </form>
        @endif
        <x-button variant="secondary" icon="pencil" :href="route('settings.landing').'#contact'">Edit contact section</x-button>
    </x-page-header>

    <x-card :padding="false">
        <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 p-4 dark:border-slate-800">
            <nav class="flex gap-1 text-sm">
                @foreach (['all' => 'All', 'unread' => 'Unread ('.$unread.')'] as $key => $label)
                    <a href="{{ route('contact-messages.index', $key === 'unread' ? ['filter' => 'unread'] : []) }}" @class(['rounded-lg px-3 py-1.5 font-medium', 'bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300' => $filter === $key, 'text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800' => $filter !== $key])>{{ $label }}</a>
                @endforeach
            </nav>
            <form method="GET" class="ml-auto flex w-full gap-2 sm:w-72">
                @if ($filter === 'unread')<input type="hidden" name="filter" value="unread">@endif
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Search name, email, message" class="form-input">
                <x-button variant="secondary" icon="search" aria-label="Search"></x-button>
            </form>
        </div>

        @forelse ($messages as $m)
            <div @class(['border-b border-slate-100 px-5 py-4 last:border-0 dark:border-slate-800', 'bg-brand-50/40 dark:bg-brand-500/5' => ! $m->isRead()])>
                <div class="flex flex-wrap items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-slate-100 text-sm font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ mb_strtoupper(mb_substr($m->name, 0, 1)) }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold text-slate-900 dark:text-white">{{ $m->name }}</span>
                            @unless ($m->isRead())<x-badge color="blue" dot>New</x-badge>@endunless
                            <span class="text-xs text-slate-400" title="{{ $m->created_at }}">{{ $m->created_at->diffForHumans() }}</span>
                        </p>
                        <p class="mt-0.5 flex flex-wrap gap-x-4 text-sm text-slate-500">
                            <a href="mailto:{{ $m->email }}?subject={{ rawurlencode('Re: your message to '.$brand->siteName()) }}" class="hover:text-brand-700 dark:hover:text-brand-400">{{ $m->email }}</a>
                            @if ($m->phone)<a href="tel:{{ preg_replace('/[^0-9+]/', '', $m->phone) }}" class="hover:text-brand-700 dark:hover:text-brand-400">{{ $m->phone }}</a>@endif
                        </p>
                        <p class="mt-2 whitespace-pre-line break-words text-sm text-slate-700 dark:text-slate-300">{{ $m->message }}</p>
                    </div>
                    <div class="flex shrink-0 items-center gap-1">
                        <x-button size="sm" variant="secondary" icon="envelope" :href="'mailto:'.$m->email.'?subject='.rawurlencode('Re: your message to '.$brand->siteName())">Reply</x-button>
                        <form method="POST" action="{{ route('contact-messages.update', $m) }}">
                            @csrf @method('PATCH')
                            <input type="hidden" name="read" value="{{ $m->isRead() ? 0 : 1 }}">
                            <x-button size="sm" variant="ghost" :icon="$m->isRead() ? 'eye' : 'check'">{{ $m->isRead() ? 'Unread' : 'Mark read' }}</x-button>
                        </form>
                        <form method="POST" action="{{ route('contact-messages.destroy', $m) }}" onsubmit="return confirm('Delete this message?')">
                            @csrf @method('DELETE')
                            <x-button size="sm" variant="ghost" icon="trash" class="text-rose-600 dark:text-rose-400" aria-label="Delete"></x-button>
                        </form>
                    </div>
                </div>
            </div>
        @empty
            <x-empty-state icon="inbox" title="{{ $filter === 'unread' ? 'No unread messages' : 'No messages yet' }}" description="When visitors use the contact form on your landing page, their messages appear here." />
        @endforelse
    </x-card>

    <div class="mt-4">{{ $messages->links() }}</div>
</x-app-layout>
