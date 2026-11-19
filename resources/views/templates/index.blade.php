@php use App\Support\Permissions as P; @endphp
<x-app-layout title="Message Templates">
    <x-page-header title="Message Templates" description="Templates approved by Meta are required for business-initiated messages and after the 24-hour window.">
        @if ($account)
            @can(P::TEMPLATES_SYNC)
                <form method="POST" action="{{ route('templates.sync') }}" x-data="{ busy: false }" @submit="busy = true">
                    @csrf
                    <input type="hidden" name="account_id" value="{{ $account->id }}">
                    <x-button ::disabled="busy"><span x-show="busy" class="spinner" x-cloak></span><x-icon name="refresh" class="h-4 w-4" x-show="!busy" /> Sync from Meta</x-button>
                </form>
            @endcan
        @endif
    </x-page-header>

    @if ($accounts->isEmpty())
        <x-card><x-empty-state icon="phone" title="No WhatsApp number available" /></x-card>
    @else
        <form method="GET" class="mb-5 grid gap-2 sm:grid-cols-4">
            <select name="account_id" class="form-input" onchange="this.form.submit()">
                @foreach ($accounts as $a)
                    <option value="{{ $a->id }}" @selected($account?->id === $a->id)>{{ $a->label() }}</option>
                @endforeach
            </select>
            <select name="status" class="form-input" onchange="this.form.submit()">
                <option value="">Any status</option>
                @foreach (['APPROVED', 'PENDING', 'REJECTED', 'PAUSED', 'DISABLED'] as $s)
                    <option value="{{ $s }}" @selected(strtoupper($filters['status'] ?? '') === $s)>{{ ucfirst(strtolower($s)) }}</option>
                @endforeach
            </select>
            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search template name" class="form-input">
            <x-button variant="secondary" icon="search">Search</x-button>
        </form>

        @if (! $templates || $templates->isEmpty())
            <x-card>
                <x-empty-state icon="template" title="No templates yet" description="Create templates in Meta WhatsApp Manager, wait for approval, then click “Sync from Meta”." />
            </x-card>
        @else
            <div class="grid gap-5 md:grid-cols-2 2xl:grid-cols-3">
                @foreach ($templates as $template)
                    <x-card class="flex flex-col">
                        <div class="mb-3 flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <a href="{{ route('templates.show', $template) }}" class="block truncate font-mono text-sm font-semibold text-slate-900 hover:text-emerald-700 dark:text-white">{{ $template->name }}</a>
                                <p class="text-xs text-slate-500">{{ $template->language }} · {{ ucfirst(strtolower((string) $template->category)) }}</p>
                            </div>
                            <x-badge :color="match (strtoupper((string) $template->status)) { 'APPROVED' => 'green', 'PENDING', 'IN_APPEAL' => 'amber', 'REJECTED', 'DISABLED' => 'red', default => 'slate' }" dot>{{ $template->status }}</x-badge>
                        </div>
                        @include('templates.partials.preview', ['template' => $template])
                        @if ($template->rejected_reason)
                            <p class="mt-2 text-xs text-rose-600">Reason: {{ $template->rejected_reason }}</p>
                        @endif
                        <div class="mt-auto flex items-center justify-between pt-4 text-xs text-slate-500">
                            <span>Synced {{ $template->synced_at?->diffForHumans() ?? '—' }}</span>
                            @if ($template->isApproved())
                                @can(P::TEMPLATES_SEND)
                                    <x-button size="sm" icon="send" :href="route('templates.show', $template)">Send</x-button>
                                @endcan
                            @endif
                        </div>
                    </x-card>
                @endforeach
            </div>
            <div class="mt-6">{{ $templates->links() }}</div>
        @endif
    @endif
</x-app-layout>
