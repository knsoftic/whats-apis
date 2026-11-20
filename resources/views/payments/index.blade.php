<x-app-layout title="Payments">
    @php $isSuper = auth()->user()->hasRole(\App\Support\Permissions::ROLE_SUPER_ADMIN); @endphp
    <div x-data="{ modal: null, status: '', activate: true, open(p) { this.modal = p; this.status = 'paid'; this.activate = true; } }">
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
                                @if ($p->admin_note)<p class="mt-1 max-w-xs whitespace-pre-line text-xs text-slate-500">{{ \Illuminate\Support\Str::limit($p->admin_note, 300) }}</p>@endif
                            </td>
                            <td class="text-right">
                                <div class="flex flex-wrap justify-end gap-1">
                                    @if ($p->gateway === 'pkpay' && $p->gateway_payment_id && ! $p->isPaid())
                                        <form method="POST" action="{{ route('payments.sync', $p) }}">@csrf<x-button size="sm" variant="secondary" icon="refresh" title="Ask PKPay for the latest status">Check</x-button></form>
                                    @endif
                                    @if ($p->isPending())
                                        <form method="POST" action="{{ route('payments.approve', $p) }}" onsubmit="return confirm('Mark {{ $p->reference }} as paid and activate the plan?')">@csrf<x-button size="sm" icon="check">Approve</x-button></form>
                                        <form method="POST" action="{{ route('payments.reject', $p) }}" onsubmit="return confirm('Reject {{ $p->reference }}?')">@csrf<x-button size="sm" variant="ghost" class="text-rose-600">Reject</x-button></form>
                                    @endif
                                    @if ($isSuper)
                                        <button type="button"
                                                @click="open({{ \Illuminate\Support\Js::from(['url' => route('payments.status', $p), 'reference' => $p->reference, 'status' => $p->status, 'customer' => $p->user?->email ?? 'Deleted user', 'plan' => $p->plan_name, 'amount' => $p->amountLabel(), 'hasPlan' => $p->plan_id !== null && $p->user_id !== null, 'activated' => ! empty($p->meta['plan_activated_at']) || $p->isPaid()]) }})"
                                                @class(['inline-flex items-center gap-1.5 rounded-xl px-3 py-1.5 text-xs font-medium transition',
                                                    'bg-brand-600 text-white shadow-sm hover:bg-brand-700' => in_array($p->status, ['expired', 'failed', 'cancelled'], true),
                                                    'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700' => ! in_array($p->status, ['expired', 'failed', 'cancelled'], true)])>
                                            <x-icon name="pencil" class="h-3.5 w-3.5" /> Change status
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-100 p-4 dark:border-slate-800">{{ $payments->links() }}</div>
        @endif
    </x-card>

    @if ($isSuper)
        {{-- Change status (Super Admin only) --}}
        <div x-show="modal" x-cloak class="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/50 sm:items-center sm:p-4" @keydown.escape.window="modal = null">
            <form method="POST" :action="modal?.url" x-data="{ submitting: false }" @submit="submitting = true" @click.outside="modal = null"
                  class="w-full max-w-lg rounded-t-2xl bg-white p-6 shadow-xl dark:bg-slate-900 sm:rounded-2xl">
                @csrf
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Change payment status</h2>
                        <p class="mt-1 font-mono text-xs text-slate-500" x-text="modal?.reference"></p>
                    </div>
                    <button type="button" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800" @click="modal = null" aria-label="Close"><x-icon name="x" class="h-5 w-5" /></button>
                </div>

                <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-1 rounded-xl bg-slate-50 p-3 text-sm dark:bg-slate-800/60">
                    <dt class="text-slate-500">Customer</dt><dd class="truncate font-medium" x-text="modal?.customer"></dd>
                    <dt class="text-slate-500">Plan / amount</dt><dd class="font-medium" x-text="(modal?.plan || '—') + ' · ' + modal?.amount"></dd>
                    <dt class="text-slate-500">Current status</dt><dd class="font-medium capitalize" x-text="modal?.status"></dd>
                </dl>

                <label class="form-label mt-5" for="new_status">New status</label>
                <select id="new_status" name="status" class="form-input" x-model="status" required>
                    @foreach (\App\Services\Payments\PaymentService::ADMIN_STATUSES as $s)
                        <option value="{{ $s }}">{{ ucfirst($s) }}</option>
                    @endforeach
                </select>

                <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200" x-show="status === 'paid' && modal?.hasPlan && !modal?.activated">
                    <label class="flex items-start gap-3">
                        <input type="hidden" name="activate_plan" value="0">
                        <input type="checkbox" name="activate_plan" value="1" x-model="activate" class="mt-0.5 rounded border-emerald-400 text-emerald-600 focus:ring-emerald-500">
                        <span><span class="font-medium">Activate the customer's plan</span> — <span x-text="modal?.plan"></span> starts now (renewals extend the current expiry).</span>
                    </label>
                </div>
                <p class="mt-4 rounded-xl bg-slate-100 p-3 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300" x-show="status === 'paid' && modal?.activated" x-cloak>The plan was already activated for this payment once — it will not be extended again.</p>
                <p class="mt-4 rounded-xl bg-amber-50 p-3 text-xs text-amber-800 dark:bg-amber-950/40 dark:text-amber-300" x-show="modal?.status === 'paid' && status !== 'paid'" x-cloak>The customer keeps their current plan. Change it on the Users page if this payment was a mistake or refunded.</p>
                <p class="mt-4 rounded-xl bg-slate-100 p-3 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300" x-show="status === 'pending'" x-cloak>Pending payments set by you are not auto-expired, so you can approve or reject them later.</p>

                <label class="form-label mt-4" for="status_note">Note <span class="font-normal text-slate-400">(optional, saved in the history)</span></label>
                <input id="status_note" type="text" name="note" maxlength="300" class="form-input" placeholder="e.g. Received in bank, TXN 12345">

                <div class="mt-6 flex justify-end gap-2">
                    <x-button type="button" variant="secondary" x-on:click="modal = null">Cancel</x-button>
                    <x-button x-bind:disabled="submitting || status === modal?.status"><span x-show="submitting" x-cloak class="spinner"></span> Save status</x-button>
                </div>
            </form>
        </div>
    @endif
    </div>
</x-app-layout>
