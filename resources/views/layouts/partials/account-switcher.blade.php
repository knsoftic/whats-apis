{{-- Switch the current WhatsApp number. "All numbers" = no filter. --}}
@if ($switcherAccounts->isNotEmpty())
    <div x-data="{ open: false }" class="relative" @keydown.escape="open = false">
        <button type="button" @click="open = !open" class="flex max-w-[220px] items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-sm hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:hover:bg-slate-700" aria-haspopup="true">
            <x-icon name="phone" class="h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
            <span class="hidden truncate sm:inline">{{ $currentAccount?->name ?? 'All numbers' }}</span>
            <x-icon name="chevron-down" class="h-4 w-4 shrink-0 text-slate-400" />
        </button>
        <div x-show="open" x-cloak @click.outside="open = false" x-transition
             class="absolute right-0 z-50 mt-2 w-72 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-lg dark:border-slate-700 dark:bg-slate-800">
            <p class="border-b border-slate-100 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-400 dark:border-slate-700">Switch WhatsApp number</p>
            <form method="POST" action="{{ route('current-account.switch') }}" class="max-h-80 overflow-y-auto py-1">
                @csrf
                <button name="account_id" value="" class="flex w-full items-center gap-3 px-4 py-2 text-left text-sm hover:bg-slate-50 dark:hover:bg-slate-700">
                    <span class="h-2 w-2 rounded-full bg-slate-300"></span>
                    <span class="flex-1">All numbers</span>
                    @if (! $currentAccount)<x-icon name="check" class="h-4 w-4 text-emerald-600" />@endif
                </button>
                @foreach ($switcherAccounts as $acc)
                    <button name="account_id" value="{{ $acc->id }}" class="flex w-full items-center gap-3 px-4 py-2 text-left text-sm hover:bg-slate-50 dark:hover:bg-slate-700">
                        <span @class(['h-2 w-2 shrink-0 rounded-full',
                            'bg-emerald-500' => $acc->isOperational(),
                            'bg-amber-500' => $acc->is_active && ! $acc->isOperational(),
                            'bg-slate-400' => ! $acc->is_active])></span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium">{{ $acc->name }}</span>
                            <span class="block truncate text-xs text-slate-500 dark:text-slate-400">{{ $acc->display_phone_number ?? 'Phone ID '.$acc->phone_number_id }}{{ $acc->is_active ? '' : ' · disabled' }}</span>
                        </span>
                        @if ($currentAccount?->id === $acc->id)<x-icon name="check" class="h-4 w-4 text-emerald-600" />@endif
                    </button>
                @endforeach
            </form>
        </div>
    </div>
@endif
