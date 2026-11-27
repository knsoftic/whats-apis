<x-app-layout title="Auto Replies">
    <x-page-header title="Auto Replies" description="Always reply, auto reply and quick action buttons — decided here from your chat history (first message, returning customer, keyword, every message). Separate from Bot Flows.">
        @if ($account)
            <x-button icon="plus" :href="route('auto-replies.create', ['account_id' => $account->id])">New auto reply</x-button>
        @endif
    </x-page-header>

    @if ($accounts->isEmpty())
        <x-card><x-empty-state icon="phone" title="No WhatsApp number yet" description="Connect a number first, then add its auto replies." /></x-card>
    @else
        <div class="mb-5 flex flex-wrap items-center gap-3">
            <form method="GET" class="w-full max-w-sm">
                <select name="account_id" class="form-input" onchange="this.form.submit()" aria-label="WhatsApp number">
                    @foreach ($accounts as $a)
                        <option value="{{ $a->id }}" @selected($account?->id === $a->id)>{{ $a->label() }}</option>
                    @endforeach
                </select>
            </form>
            <div class="flex flex-wrap gap-2 text-xs">
                <span class="text-slate-500">Start from:</span>
                @foreach (['welcome' => 'Welcome new customers', 'returning' => 'Welcome back', 'away' => 'Away message', 'always' => 'Always reply'] as $preset => $label)
                    <a href="{{ route('auto-replies.create', ['account_id' => $account?->id, 'preset' => $preset]) }}" class="rounded-full bg-slate-100 px-2.5 py-1 font-medium text-slate-700 hover:bg-brand-50 hover:text-brand-700 dark:bg-slate-800 dark:text-slate-300">{{ $label }}</a>
                @endforeach
            </div>
        </div>

        @unless ($allowed)
            <div class="mb-5 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
                Automation (bots) is not included in this number's plan, so auto replies will not answer. Upgrade the plan to activate them.
            </div>
        @endunless

        <div class="mb-5 flex items-start gap-3 rounded-2xl border border-slate-200 bg-white px-5 py-4 text-sm text-slate-600 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300">
            <x-icon name="info" class="mt-0.5 h-5 w-5 shrink-0 text-brand-600" />
            <div class="space-y-1">
                <p>Auto replies work on their own — they are <strong>not</strong> Bot Flows and ignore the bot pause. For every customer message the <strong>highest-priority matching</strong> rule answers once.</p>
                <p>By default a rule stays quiet while an agent is chatting (an agent replied in the last {{ config('whatsapp.auto_reply.agent_active_minutes', 30) }} min) — tick <em>“Also while an agent is chatting”</em> to answer anyway.</p>
                @if ($botFlows > 0)
                    <p class="text-amber-700 dark:text-amber-300">This number also has {{ $botFlows }} active Bot Flow(s). When both answer the same message, the customer gets both replies.</p>
                @endif
            </div>
        </div>

        @if ($rules->isEmpty())
            <x-card>
                <x-empty-state icon="reply" title="No auto replies for this number"
                               description="Greet new customers with quick action buttons, welcome back returning ones, or send an away message outside opening hours.">
                    <x-button icon="plus" :href="route('auto-replies.create', ['account_id' => $account->id])">New auto reply</x-button>
                </x-empty-state>
            </x-card>
        @else
            <div class="grid gap-4 lg:grid-cols-2">
                @foreach ($rules as $rule)
                    <x-card class="flex flex-col">
                        <div class="flex items-start gap-3">
                            <div class="min-w-0 flex-1">
                                <a href="{{ route('auto-replies.edit', $rule) }}" class="block truncate text-base font-semibold text-slate-900 hover:text-brand-700 dark:text-white">{{ $rule->name }}</a>
                                <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                    <x-badge :color="match ($rule->trigger) { 'first_message' => 'green', 'returning' => 'blue', 'keyword' => 'violet', default => 'amber' }">{{ \App\Models\AutoReply::TRIGGERS[$rule->trigger] ?? $rule->trigger }}</x-badge>
                                    @if ($rule->priority)<x-badge color="slate">Priority {{ $rule->priority }}</x-badge>@endif
                                    @unless ($rule->is_active)<x-badge color="slate" dot>Off</x-badge>@endunless
                                </div>
                            </div>
                            <form method="POST" action="{{ route('auto-replies.toggle', $rule) }}">
                                @csrf @method('PATCH')
                                <button type="submit" role="switch" aria-checked="{{ $rule->is_active ? 'true' : 'false' }}" title="{{ $rule->is_active ? 'Switch off' : 'Switch on' }}"
                                        class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition {{ $rule->is_active ? 'bg-brand-600' : 'bg-slate-300 dark:bg-slate-700' }}">
                                    <span class="inline-block h-5 w-5 transform rounded-full bg-white shadow transition {{ $rule->is_active ? 'translate-x-5' : 'translate-x-0.5' }}"></span>
                                </button>
                            </form>
                        </div>
                        <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ $rule->summary() }}</p>
                        <div class="mt-3 rounded-xl bg-emerald-50 px-3 py-2 text-sm text-slate-800 dark:bg-emerald-500/10 dark:text-slate-200">
                            <p class="line-clamp-3 whitespace-pre-line">{{ $rule->reply_text }}</p>
                            @if ($rule->buttonList())
                                <div class="mt-2 flex flex-wrap gap-1.5">
                                    @foreach ($rule->buttonList() as $b)
                                        <span class="rounded-full border border-emerald-200 bg-white px-2.5 py-0.5 text-xs font-medium text-sky-700 dark:border-emerald-900 dark:bg-slate-900 dark:text-sky-300">{{ $b['title'] }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                        <div class="mt-auto flex flex-wrap items-center gap-2 pt-4 text-xs text-slate-500">
                            <span>{{ number_format($rule->runs_count) }} replies sent{{ $rule->last_run_at ? ' · last '.$rule->last_run_at->diffForHumans() : '' }}</span>
                            <span class="ml-auto flex gap-2">
                                <x-button size="sm" variant="secondary" icon="pencil" :href="route('auto-replies.edit', $rule)">Edit</x-button>
                                <form method="POST" action="{{ route('auto-replies.destroy', $rule) }}" onsubmit="return confirm('Delete this auto reply?')">
                                    @csrf @method('DELETE')
                                    <x-button size="sm" variant="secondary" icon="trash" aria-label="Delete">Delete</x-button>
                                </form>
                            </span>
                        </div>
                    </x-card>
                @endforeach
            </div>
        @endif
    @endif
</x-app-layout>
