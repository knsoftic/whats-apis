<x-app-layout title="Payments">
    <x-page-header title="Payments" description="Plan purchases from customers. Approve manual transfers here; PKPay payments activate automatically.">
        <x-button variant="secondary" icon="key" :href="route('settings.edit', 'payments')">Payment settings</x-button>
    </x-page-header>

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <x-stat-card label="Awaiting approval" :value="$totals['awaiting']" icon="clock" color="amber" />
        @forelse ($totals['paid'] as $currency => $total)
            <x-stat-card :label="'Received ('.$currency.')'" :value="number_format((float) $total, 2)" icon="check" color="emerald" />
        @empty
            <x-stat-card label="Received" value="0" icon="check" color="emerald" />
        @endforelse
    </div>

    <x-card :padding="false">
        <form method="GET" class="grid gap-2 border-b border-slate-100 p-4 dark:border-slate-800 sm:grid-cols-4">
            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Reference, transaction, customer" class="form-input sm:col-span-2">
            <select name="status" class="form-input">
                <option value="">Any status</option>
                @foreach (['pending', 'paid', 'failed', 'expired', 'cancelled', 'rejected'] as $s)
                    <option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ ucfirst($s) }}</option>
                @endforeach
            </select>
            <div class="flex gap-2">
                <select name="gateway" class="form-input">
                    <option value="">Any method</option>
                    <option value="pkpay" @selected(($filters['gateway'] ?? '') === 'pkpay')>PKPay</option>
                    <option value="manual" @selected(($filters['gateway'] ?? '') === 'manual')>Manual</option>
                </select>
                <x-button variant="secondary" icon="filter" aria-label="Filter"></x-button>
            </div>
        </form>

        @if ($payments->isEmpty())
            <x-empty-state icon="inbox" title="No payments yet" />
        @else
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th>Payment</th><th>Customer</th><th>Plan</th><th>Amount</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
                    <tbody>
                    @foreach ($payments as $p)
                        <tr>
                            <td>
                                <p class="font-mono text-xs">{{ $p->reference }}</p>
                                <p class="text-xs text-slate-500">{{ $p->gatewayLabel() }} · {{ $p->created_at->diffForHumans() }}</p>
                                @if ($p->gateway_transaction_id)<p class="font-mono text-[11px] text-slate-400">{{ $p->gateway_transaction_id }}</p>@endif
                            </td>
                            <td class="text-sm">{{ $p->user?->name ?? 'Deleted user' }}<span class="block text-xs text-slate-500">{{ $p->user?->email }}</span></td>
                            <td class="text-sm">{{ $p->plan_name }}</td>
                            <td class="whitespace-nowrap text-sm font-medium">{{ $p->amountLabel() }}</td>
                            <td>
                                <x-badge :color="$p->statusColor()" dot>{{ $p->status }}</x-badge>
                                @if ($p->customer_note)<p class="mt-1 max-w-xs text-xs text-slate-600 dark:text-slate-400">“{{ \Illuminate\Support\Str::limit($p->customer_note, 120) }}”</p>@endif
                                @if ($p->admin_note)<p class="mt-1 max-w-xs text-xs text-slate-500">{{ \Illuminate\Support\Str::limit($p->admin_note, 120) }}</p>@endif
                            </td>
                            <td class="text-right">
                                @if ($p->isPending())
                                    <div class="flex justify-end gap-1">
                                        @if ($p->gateway === 'pkpay')
                                            <form method="POST" action="{{ route('payments.sync', $p) }}">@csrf<x-button size="sm" variant="secondary" icon="refresh">Check</x-button></form>
                                        @endif
                                        <form method="POST" action="{{ route('payments.approve', $p) }}" onsubmit="return confirm('Mark {{ $p->reference }} as paid and activate the plan?')">@csrf<x-button size="sm" icon="check">Approve</x-button></form>
                                        <form method="POST" action="{{ route('payments.reject', $p) }}" onsubmit="return confirm('Reject {{ $p->reference }}?')">@csrf<x-button size="sm" variant="ghost" class="text-rose-600">Reject</x-button></form>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-100 p-4 dark:border-slate-800">{{ $payments->links() }}</div>
        @endif
    </x-card>
</x-app-layout>
