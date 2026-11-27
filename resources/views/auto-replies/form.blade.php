@php
    $editing = $rule->exists;
    $state = [
        'trigger' => old('trigger', $rule->trigger),
        'match_type' => old('match_type', $rule->match_type ?? 'contains'),
        'keywords' => old('keywords', $rule->keywords ?? ''),
        'gap_hours' => (int) old('gap_hours', $rule->gap_hours ?? 0),
        'message_types' => array_values((array) old('message_types', $rule->message_types ?? [])),
        'while_chatting' => (bool) old('while_chatting', $rule->while_chatting ?? false),
        'hours_mode' => old('hours_mode', $rule->hours_mode ?? 'always'),
        'days' => array_map('intval', (array) old('days', $rule->days ?? [1, 2, 3, 4, 5, 6])),
        'from_time' => old('from_time', $rule->from_time ?? '09:00'),
        'to_time' => old('to_time', $rule->to_time ?? '18:00'),
        'reply_text' => old('reply_text', $rule->reply_text ?? ''),
        'buttons' => array_values(array_map(fn ($b) => ['id' => (string) ($b['id'] ?? ''), 'title' => (string) ($b['title'] ?? ''), 'reply' => (string) ($b['reply'] ?? '')], (array) old('buttons', $rule->buttons ?? []))),
        'business' => $account->verified_name ?: $account->name,
    ];
    $days = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
@endphp
<x-app-layout :title="$editing ? 'Edit auto reply' : 'New auto reply'">
    <div class="mx-auto max-w-6xl">
        <x-page-header :title="$editing ? 'Edit auto reply' : 'New auto reply'" :description="'For '.$account->label().' · separate from Bot Flows'">
            <x-button variant="secondary" icon="arrow-left" :href="route('auto-replies.index', ['account_id' => $account->id])">Back</x-button>
        </x-page-header>

        @if ($errors->any())
            <div class="mb-5 rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-500/10 dark:text-rose-200">
                <p class="font-semibold">Please fix:</p>
                <ul class="mt-1 list-disc pl-5">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        <form method="POST" action="{{ $editing ? route('auto-replies.update', $rule) : route('auto-replies.store') }}" x-data="autoReplyForm(@js($state))" class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
            @csrf
            @if ($editing) @method('PUT') @else <input type="hidden" name="whatsapp_account_id" value="{{ $account->id }}"> @endif

            <div class="min-w-0 space-y-5">
                <x-card title="1. Name">
                    <input type="text" name="name" class="form-input" maxlength="120" required value="{{ old('name', $rule->name) }}" placeholder="Welcome new customers">
                </x-card>

                <x-card title="2. When should it reply?" subtitle="Read from this app's chat history for this number.">
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach (\App\Models\AutoReply::TRIGGERS as $value => $label)
                            <label class="flex cursor-pointer items-start gap-2 rounded-xl border px-3 py-2.5 text-sm" :class="trigger === '{{ $value }}' ? 'border-brand-500 bg-brand-50 dark:bg-brand-500/10' : 'border-slate-200 dark:border-slate-700'">
                                <input type="radio" name="trigger" value="{{ $value }}" x-model="trigger" class="mt-0.5 text-brand-600 focus:ring-brand-500">
                                <span>
                                    <span class="font-medium">{{ $label }}</span>
                                    <span class="block text-xs text-slate-500">{{ match ($value) {
                                        'first_message' => 'The customer never wrote to this number before.',
                                        'returning' => 'They wrote before — optionally only after some quiet hours.',
                                        'keyword' => 'The message contains one of your words.',
                                        default => 'Any message, any time (use a cooldown to avoid repeats).',
                                    } }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>

                    <div x-show="trigger === 'keyword'" class="mt-4 grid gap-3 sm:grid-cols-[1fr_180px]">
                        <div>
                            <label class="form-label">Keywords <span class="font-normal text-slate-400">(comma separated)</span></label>
                            <input type="text" name="keywords" x-model="keywords" maxlength="1000" class="form-input" placeholder="price, rates, kitne ka">
                        </div>
                        <div>
                            <label class="form-label">Match</label>
                            <select name="match_type" x-model="match_type" class="form-input">
                                @foreach (\App\Models\BotFlow::MATCHES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                            </select>
                        </div>
                    </div>

                    <div x-show="trigger === 'returning'" class="mt-4 max-w-xs">
                        <label class="form-label">Only after the customer was quiet for</label>
                        <div class="flex items-center gap-2">
                            <input type="number" name="gap_hours" x-model.number="gap_hours" min="0" max="720" class="form-input w-28">
                            <span class="text-sm text-slate-500">hours</span>
                        </div>
                        <p class="form-help">0 = every message of a returning customer. 24 = when they come back after a day or more.</p>
                    </div>
                </x-card>

                <x-card title="3. Extra conditions" subtitle="All optional.">
                    <div class="space-y-5">
                        <div>
                            <p class="form-label">Only for these message types <span class="font-normal text-slate-400">(none ticked = any)</span></p>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach (\App\Support\BotGraph::MESSAGE_TYPES as $value => $label)
                                    <label class="cursor-pointer">
                                        <input type="checkbox" name="message_types[]" value="{{ $value }}" x-model="message_types" class="peer sr-only">
                                        <span class="block rounded-lg border border-slate-200 px-2.5 py-1 text-xs peer-checked:border-brand-500 peer-checked:bg-brand-50 peer-checked:text-brand-800 dark:border-slate-700 dark:peer-checked:bg-brand-500/10 dark:peer-checked:text-brand-200">{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <div>
                            <p class="form-label">Time</p>
                            <div class="flex flex-wrap gap-2">
                                @foreach (\App\Models\AutoReply::HOURS_MODES as $value => $label)
                                    <label class="flex cursor-pointer items-center gap-2 rounded-xl border px-3 py-1.5 text-sm" :class="hours_mode === '{{ $value }}' ? 'border-brand-500 bg-brand-50 dark:bg-brand-500/10' : 'border-slate-200 dark:border-slate-700'">
                                        <input type="radio" name="hours_mode" value="{{ $value }}" x-model="hours_mode" class="text-brand-600 focus:ring-brand-500"> {{ $label }}
                                    </label>
                                @endforeach
                            </div>
                            <div x-show="hours_mode !== 'always'" class="mt-3 space-y-2">
                                <div class="flex flex-wrap gap-1">
                                    @foreach ($days as $n => $d)
                                        <label class="cursor-pointer">
                                            <input type="checkbox" name="days[]" value="{{ $n }}" x-model.number="days" class="peer sr-only" :disabled="hours_mode === 'always'">
                                            <span class="block rounded-lg border border-slate-200 px-2 py-1 text-xs peer-checked:border-brand-500 peer-checked:bg-brand-50 peer-checked:text-brand-800 dark:border-slate-700 dark:peer-checked:bg-brand-500/10 dark:peer-checked:text-brand-200">{{ $d }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                <div class="flex flex-wrap items-center gap-2 text-sm">
                                    <input type="time" name="from_time" x-model="from_time" class="form-input w-32" :disabled="hours_mode === 'always'">
                                    <span>to</span>
                                    <input type="time" name="to_time" x-model="to_time" class="form-input w-32" :disabled="hours_mode === 'always'">
                                    <span class="text-xs text-slate-500">({{ config('app.timezone') }})</span>
                                </div>
                                <p class="form-help">Opening hours. “Outside” = an away message when you are closed. 22:00 → 06:00 works overnight.</p>
                            </div>
                        </div>

                        <label class="flex items-start gap-3 text-sm">
                            <input type="hidden" name="while_chatting" value="0">
                            <input type="checkbox" name="while_chatting" value="1" x-model="while_chatting" class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800">
                            <span><span class="font-medium">Also while an agent is chatting</span>
                                <span class="block text-xs text-slate-500">Off: stays quiet when an agent replied in this chat in the last {{ config('whatsapp.auto_reply.agent_active_minutes', 30) }} minutes. On: always answers (e.g. a keyword answer the agent wants anyway).</span></span>
                        </label>

                        <div class="grid max-w-md grid-cols-2 gap-3">
                            <div>
                                <label class="form-label">Cooldown (minutes)</label>
                                <input type="number" name="cooldown_minutes" min="0" max="10080" class="form-input" value="{{ old('cooldown_minutes', $rule->cooldown_minutes ?? 0) }}">
                                <p class="form-help">At most once per X minutes per chat. 0 = no limit.</p>
                            </div>
                            <div>
                                <label class="form-label">Priority</label>
                                <input type="number" name="priority" min="0" max="1000" class="form-input" value="{{ old('priority', $rule->priority ?? 0) }}">
                                <p class="form-help">Higher wins when several rules match.</p>
                            </div>
                        </div>
                    </div>
                </x-card>

                <x-card title="4. Reply" subtitle="Use {{'{'}}{{'{'}}contact_name{{'}'}}{{'}'}}, {{'{'}}{{'{'}}business_name{{'}'}}{{'}'}}, {{'{'}}{{'{'}}phone{{'}'}}{{'}'}}.">
                    <textarea name="reply_text" rows="5" x-model="reply_text" :maxlength="buttons.length ? 1024 : 4096" required class="form-input" placeholder="Hi {{'{'}}{{'{'}}contact_name{{'}'}}{{'}'}}! How can we help?"></textarea>
                    <p class="form-help"><span x-text="reply_text.length"></span>/<span x-text="buttons.length ? 1024 : 4096"></span> · *bold* _italic_ and links work.</p>

                    <div class="mt-5">
                        <div class="flex items-center justify-between">
                            <p class="form-label !mb-0">Quick action buttons <span class="font-normal text-slate-400">(optional, max 3)</span></p>
                            <button type="button" @click="addButton()" x-show="buttons.length < 3" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-medium text-brand-700 hover:bg-brand-50 dark:text-brand-400 dark:hover:bg-brand-500/10">
                                <x-icon name="plus" class="h-4 w-4" /> Add button
                            </button>
                        </div>
                        <p class="form-help">The customer taps a button → this app sends that button's answer straight away.</p>
                        <div class="mt-3 space-y-3">
                            <template x-for="(b, i) in buttons" :key="i">
                                <div class="grid gap-2 rounded-xl border border-slate-200 p-3 dark:border-slate-700 sm:grid-cols-[180px_1fr_auto]">
                                    <input type="hidden" :name="`buttons[${i}][id]`" :value="b.id">
                                    <input type="text" :name="`buttons[${i}][title]`" x-model="b.title" maxlength="20" class="form-input" placeholder="Button title" aria-label="Button title">
                                    <textarea :name="`buttons[${i}][reply]`" x-model="b.reply" rows="2" maxlength="1024" class="form-input" placeholder="Answer sent when tapped" aria-label="Button answer"></textarea>
                                    <button type="button" @click="buttons.splice(i, 1)" class="self-start rounded-lg p-2 text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-500/10" aria-label="Remove button"><x-icon name="trash" class="h-4 w-4" /></button>
                                </div>
                            </template>
                        </div>
                    </div>
                </x-card>

                <x-card>
                    <label class="flex items-center gap-3 text-sm">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $rule->is_active ?? true)) class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800">
                        <span><span class="font-medium">Active</span> — replies to incoming messages right away.</span>
                    </label>
                </x-card>

                <div class="flex flex-wrap items-center justify-end gap-3">
                    <x-button variant="secondary" :href="route('auto-replies.index', ['account_id' => $account->id])">Cancel</x-button>
                    <x-button icon="check">{{ $editing ? 'Save changes' : 'Create auto reply' }}</x-button>
                </div>
            </div>

            {{-- Live preview --}}
            <div class="lg:sticky lg:top-6 lg:self-start">
                <x-card title="Preview" subtitle="How the customer sees it">
                    <div class="rounded-2xl bg-[#efeae2] p-4 dark:bg-slate-800">
                        <div class="ml-auto max-w-[85%]">
                            <div class="rounded-2xl rounded-tr-sm bg-[#d9fdd3] px-3 py-2 text-sm text-slate-900 shadow-sm dark:bg-emerald-900/60 dark:text-emerald-50">
                                <p class="whitespace-pre-line break-words" x-text="preview() || 'Your reply…'"></p>
                                <p class="mt-1 text-right text-[10px] text-slate-500 dark:text-emerald-200/70">now ✓✓</p>
                            </div>
                            <template x-for="(b, i) in buttons.filter(x => x.title)" :key="i">
                                <div class="mt-1 rounded-xl bg-white px-3 py-2 text-center text-sm font-medium text-sky-600 shadow-sm dark:bg-slate-900 dark:text-sky-300" x-text="b.title"></div>
                            </template>
                        </div>
                    </div>
                    <p class="mt-3 text-xs text-slate-500" x-text="explain()"></p>
                </x-card>
            </div>
        </form>

        @if ($editing)
            <form method="POST" action="{{ route('auto-replies.destroy', $rule) }}" class="mt-6" onsubmit="return confirm('Delete this auto reply?')">
                @csrf @method('DELETE')
                <x-button variant="danger" icon="trash">Delete auto reply</x-button>
            </form>
        @endif
    </div>

</x-app-layout>
