@php $editing = $flow->exists; @endphp
<x-app-layout :title="$editing ? 'Edit bot flow' : 'New bot flow'">
    <div class="mx-auto max-w-4xl">
        <x-page-header :title="$editing ? 'Edit '.$flow->name : 'New bot flow'" description="What should the bot send, and when?">
            <x-button variant="secondary" icon="arrow-left" :href="route('bots.index', ['account_id' => $flow->whatsapp_account_id])">Bot flows</x-button>
        </x-page-header>

        <form method="POST" action="{{ $editing ? route('bots.update', $flow) : route('bots.store') }}"
              x-data="botBuilder(@js(['steps' => old('steps') ? json_decode(old('steps'), true) : $flow->steps, 'targets' => $targets, 'accountId' => old('whatsapp_account_id', $flow->whatsapp_account_id), 'flowId' => $flow->id, 'trigger' => old('trigger_type', $flow->trigger_type), 'maxSteps' => config('whatsapp.bot.max_steps')]))">
            @csrf
            @if ($editing) @method('PUT') @endif
            <input type="hidden" name="steps" :value="json">

            @if ($errors->any())
                <div class="mb-6 rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm text-rose-700 dark:border-rose-900 dark:bg-rose-500/10 dark:text-rose-300">
                    <ul class="list-disc space-y-1 pl-5">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                </div>
            @endif

            <x-card title="1. Trigger">
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label class="form-label" for="name">Flow name</label>
                        <input id="name" name="name" class="form-input" value="{{ old('name', $flow->name) }}" required maxlength="120" placeholder="e.g. Main menu">
                    </div>
                    <div>
                        <label class="form-label" for="whatsapp_account_id">WhatsApp number</label>
                        @if ($editing)
                            <input type="hidden" name="whatsapp_account_id" value="{{ $flow->whatsapp_account_id }}">
                            <p class="form-input bg-slate-50 dark:bg-slate-800/60">{{ $flow->account->label() }}</p>
                        @else
                            <select id="whatsapp_account_id" name="whatsapp_account_id" class="form-input" x-model="accountId">
                                @foreach ($accounts as $a)
                                    <option value="{{ $a->id }}">{{ $a->label() }}</option>
                                @endforeach
                            </select>
                        @endif
                    </div>
                    <div>
                        <label class="form-label" for="trigger_type">When does it run?</label>
                        <select id="trigger_type" name="trigger_type" class="form-input" x-model="trigger">
                            @foreach (\App\Models\BotFlow::TRIGGERS as $v => $l)
                                <option value="{{ $v }}">{{ $l }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div x-show="trigger === 'keyword'">
                        <label class="form-label" for="match_type">Match</label>
                        <select id="match_type" name="match_type" class="form-input">
                            @foreach (\App\Models\BotFlow::MATCHES as $v => $l)
                                <option value="{{ $v }}" @selected(old('match_type', $flow->match_type) === $v)>{{ $l }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="sm:col-span-2" x-show="trigger === 'keyword'">
                        <label class="form-label" for="keywords">Keywords (comma separated)</label>
                        <input id="keywords" name="keywords" class="form-input" value="{{ old('keywords', $flow->keywords) }}" placeholder="price, rates, kitna, pricing">
                        <p class="form-help">Not case-sensitive. Emoji and Hindi/Urdu words work too.</p>
                    </div>
                    <div>
                        <label class="form-label" for="priority">Priority</label>
                        <input id="priority" name="priority" type="number" min="0" max="1000" class="form-input" value="{{ old('priority', $flow->priority) }}">
                        <p class="form-help">Higher wins when several keyword flows match.</p>
                    </div>
                    <div>
                        <label class="form-label" for="cooldown_minutes">Cooldown per contact (minutes)</label>
                        <input id="cooldown_minutes" name="cooldown_minutes" type="number" min="0" class="form-input" value="{{ old('cooldown_minutes', $flow->cooldown_minutes) }}">
                        <p class="form-help">0 = every time. Useful for Fallback (e.g. 60).</p>
                    </div>
                    <label class="flex items-center gap-3 text-sm">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800" @checked(old('is_active', $flow->is_active))>
                        Active
                    </label>
                </div>
            </x-card>

            <x-card class="mt-6" title="2. Steps" subtitle="Sent in order. A Handoff step pauses the bot so your team can take over.">
                <div class="space-y-4">
                    <template x-for="(step, i) in steps" :key="i">
                        <div class="rounded-2xl border border-slate-200 p-4 dark:border-slate-700">
                            <div class="mb-3 flex items-center gap-2">
                                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-brand-600 text-xs font-semibold text-white" x-text="i + 1"></span>
                                <span class="text-sm font-semibold" x-text="label(step.type)"></span>
                                <span x-show="step.type === 'menu'" class="text-xs text-slate-500" x-text="'· ' + menuKind(step)"></span>
                                <div class="ml-auto flex gap-1">
                                    <button type="button" class="rounded-lg px-2 py-1 text-xs hover:bg-slate-100 dark:hover:bg-slate-800" @click="move(i, -1)" :disabled="i === 0">↑</button>
                                    <button type="button" class="rounded-lg px-2 py-1 text-xs hover:bg-slate-100 dark:hover:bg-slate-800" @click="move(i, 1)" :disabled="i === steps.length - 1">↓</button>
                                    <button type="button" class="rounded-lg px-2 py-1 text-xs text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10" @click="remove(i)" x-show="steps.length > 1">Remove</button>
                                </div>
                            </div>

                            <template x-if="step.type === 'text'">
                                <div>
                                    <textarea x-model="step.text" rows="3" maxlength="4096" class="form-input" placeholder="Hello! 👋 Thanks for contacting us."></textarea>
                                    <p class="form-help">*bold* _italic_ ~strike~ and links work.</p>
                                </div>
                            </template>

                            <template x-if="step.type === 'menu'">
                                <div class="space-y-3">
                                    <textarea x-model="step.text" rows="2" maxlength="1024" class="form-input" placeholder="How can we help you today?"></textarea>
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <input x-model="step.footer" maxlength="60" class="form-input" placeholder="Footer (optional, max 60)">
                                        <input x-model="step.button_label" maxlength="20" class="form-input" placeholder="List button label, e.g. Options" x-show="step.options.length > 3">
                                    </div>
                                    <template x-for="(opt, j) in step.options" :key="j">
                                        <div class="grid gap-2 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/50 sm:grid-cols-[1fr_1fr_auto]">
                                            <input x-model="opt.title" :maxlength="titleMax(step)" class="form-input" :placeholder="'Option ' + (j + 1) + ' title (max ' + titleMax(step) + ')'">
                                            <select x-model="opt.flow_id" class="form-input">
                                                <option value="">Opens flow…</option>
                                                <template x-for="t in targets" :key="t.id">
                                                    <option :value="String(t.id)" x-text="t.name + (t.id === flowId ? ' (this flow)' : '')"></option>
                                                </template>
                                            </select>
                                            <button type="button" class="rounded-lg px-2 text-xs text-rose-600" @click="removeOption(step, j)" x-show="step.options.length > 1">✕</button>
                                            <input x-show="step.options.length > 3" x-model="opt.description" maxlength="72" class="form-input sm:col-span-3" placeholder="Description (optional, max 72)">
                                        </div>
                                    </template>
                                    <div class="flex items-center gap-3">
                                        <button type="button" class="text-sm font-medium text-brand-600" @click="addOption(step)" x-show="step.options.length < 10">+ Add option</button>
                                        <span class="text-xs text-slate-500">1–3 options = buttons · 4–10 = list</span>
                                    </div>
                                    <p x-show="!targets.length" class="text-xs text-amber-600">Create the flows the options should open first (trigger “Menu only”), then come back and link them.</p>
                                </div>
                            </template>

                            <template x-if="step.type === 'image' || step.type === 'document'">
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <input x-model="step.url" class="form-input sm:col-span-2" :placeholder="step.type === 'image' ? 'https://… .jpg / .png (public link)' : 'https://… .pdf (public link)'">
                                    <input x-model="step.caption" maxlength="1024" class="form-input" placeholder="Caption (optional)">
                                    <input x-show="step.type === 'document'" x-model="step.filename" maxlength="240" class="form-input" placeholder="File name shown (optional)">
                                </div>
                            </template>

                            <template x-if="step.type === 'handoff'">
                                <div>
                                    <textarea x-model="step.text" rows="2" maxlength="1024" class="form-input" placeholder="Message before handing over (optional)"></textarea>
                                    <p class="form-help">After this step the bot stops in this chat for {{ config('whatsapp.bot.handoff_hours') }} hours. The chat stays open for your agents.</p>
                                </div>
                            </template>
                        </div>
                    </template>

                    <div class="flex flex-wrap gap-2" x-show="steps.length < maxSteps">
                        <span class="self-center text-sm text-slate-500">Add step:</span>
                        <button type="button" class="rounded-xl border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800" @click="add('text')">Text</button>
                        <button type="button" class="rounded-xl border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800" @click="add('menu')">Menu</button>
                        <button type="button" class="rounded-xl border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800" @click="add('image')">Image</button>
                        <button type="button" class="rounded-xl border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800" @click="add('document')">Document</button>
                        <button type="button" class="rounded-xl border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800" @click="add('handoff')">Handoff to agent</button>
                    </div>
                </div>
            </x-card>

            <div class="mt-6 flex justify-end gap-2">
                <x-button variant="secondary" :href="route('bots.index', ['account_id' => $flow->whatsapp_account_id])">Cancel</x-button>
                <x-button>{{ $editing ? 'Save flow' : 'Create flow' }}</x-button>
            </div>
        </form>
    </div>
</x-app-layout>
