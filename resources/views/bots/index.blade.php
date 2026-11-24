<x-app-layout title="Bot Flows">
    <x-page-header title="Bot Flows" description="Automatic replies for incoming WhatsApp messages: keywords, welcome message, menus with buttons, fallback and handoff to an agent.">
        @if ($account)
            <x-button icon="plus" :href="route('bots.create', ['account_id' => $account->id])">New bot flow</x-button>
        @endif
    </x-page-header>

    @if ($accounts->isEmpty())
        <x-card><x-empty-state icon="phone" title="No WhatsApp number yet" description="Connect a number first, then build its bot." /></x-card>
    @else
        <form method="GET" class="mb-5 max-w-sm">
            <select name="account_id" class="form-input" onchange="this.form.submit()">
                @foreach ($accounts as $a)
                    <option value="{{ $a->id }}" @selected($account?->id === $a->id)>{{ $a->label() }}</option>
                @endforeach
            </select>
        </form>

        @unless ($botsAllowed)
            <div class="mb-5 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
                Bot flows are not included in this number's plan, so they will not reply. Upgrade the plan to activate them.
            </div>
        @endunless

        @if ($flows->isEmpty())
            <x-card>
                <x-empty-state icon="bolt" title="No bot flows for this number"
                               description="Start with a Welcome flow (first message) that shows a menu, plus a Fallback flow with a handoff to your team.">
                    <x-button icon="plus" :href="route('bots.create', ['account_id' => $account->id])">New bot flow</x-button>
                </x-empty-state>
            </x-card>
        @else
            <x-card :padding="false">
                <div class="overflow-x-auto">
                    <table class="table-base">
                        <thead><tr><th>Flow</th><th>Trigger</th><th>Nodes</th><th>Runs</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
                        <tbody>
                        @foreach ($flows as $flow)
                            <tr>
                                <td>
                                    <a href="{{ route('bots.edit', $flow) }}" class="font-medium text-slate-900 hover:text-brand-700 dark:text-white">{{ $flow->name }}</a>
                                    <p class="text-xs text-slate-500">Priority {{ $flow->priority }}{{ $flow->cooldown_minutes ? ' · cooldown '.$flow->cooldown_minutes.' min' : '' }}</p>
                                </td>
                                <td class="text-xs">
                                    <x-badge :color="match ($flow->trigger_type) { 'any' => 'violet', 'keyword' => 'blue', 'welcome' => 'green', 'fallback' => 'amber', default => 'slate' }">{{ \App\Models\BotFlow::TRIGGERS[$flow->trigger_type] ?? $flow->trigger_type }}</x-badge>
                                    @if ($flow->trigger_type === 'keyword')
                                        <p class="mt-1 max-w-xs truncate text-slate-500">{{ \App\Models\BotFlow::MATCHES[$flow->match_type] ?? '' }}: {{ $flow->keywords }}</p>
                                    @endif
                                </td>
                                <td class="text-xs text-slate-600 dark:text-slate-400">{{ \App\Support\BotGraph::summary($flow->flowGraph()) }}</td>
                                <td class="whitespace-nowrap text-xs">{{ number_format($flow->runs_count) }}<span class="block text-slate-400">{{ $flow->last_run_at?->diffForHumans() ?? 'never' }}</span></td>
                                <td>
                                    <form method="POST" action="{{ route('bots.toggle', $flow) }}">
                                        @csrf @method('PATCH')
                                        <button class="cursor-pointer"><x-badge :color="$flow->is_active ? 'green' : 'slate'" dot>{{ $flow->is_active ? 'On' : 'Off' }}</x-badge></button>
                                    </form>
                                </td>
                                <td class="text-right">
                                    <div class="flex justify-end gap-1">
                                        <x-button size="sm" variant="ghost" icon="pencil" :href="route('bots.edit', $flow)">Edit</x-button>
                                        <form method="POST" action="{{ route('bots.destroy', $flow) }}" onsubmit="return confirm('Delete bot flow {{ addslashes($flow->name) }}?')">
                                            @csrf @method('DELETE')
                                            <x-button size="sm" variant="ghost" icon="trash" class="text-rose-600">Delete</x-button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </x-card>
        @endif

        <x-card class="mt-6" title="How the bot decides">
            <ol class="list-decimal space-y-1 pl-5 text-sm text-slate-600 dark:text-slate-400">
                <li>The customer tapped a <strong>menu button / list option</strong> → the flow that option points to.</li>
                <li>The message matches a <strong>keyword</strong> flow → highest priority first.</li>
                <li>It is the contact's <strong>first message</strong> → Welcome flow.</li>
                <li>Otherwise → <strong>Fallback</strong> flow (use a cooldown so it doesn't repeat every message).</li>
            </ol>
            <p class="mt-3 text-xs text-slate-500">Bots stay quiet in a chat for {{ config('whatsapp.bot.pause_after_agent_minutes') }} minutes after an agent replies, and for {{ config('whatsapp.bot.handoff_hours') }} hours after a “Handoff to agent” step. Bot messages count towards the plan's daily message limit.</p>
        </x-card>
    @endif
</x-app-layout>
