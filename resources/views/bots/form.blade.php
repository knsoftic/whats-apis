@php
    $editing = $flow->exists;
    $cfg = [
        'graph' => $graph,
        'settings' => [
            'name' => old('name', $flow->name ?? ''),
            'whatsapp_account_id' => (int) old('whatsapp_account_id', $flow->whatsapp_account_id),
            'trigger_type' => old('trigger_type', $flow->trigger_type),
            'match_type' => old('match_type', $flow->match_type ?? 'contains'),
            'keywords' => old('keywords', $flow->keywords ?? ''),
            'priority' => (int) old('priority', $flow->priority ?? 0),
            'cooldown_minutes' => (int) old('cooldown_minutes', $flow->cooldown_minutes ?? 0),
            'is_active' => (bool) old('is_active', $flow->is_active ?? true),
        ],
        'accounts' => $accounts->map(fn ($a) => ['id' => $a->id, 'label' => $a->label()])->values(),
        'targets' => $targets,
        'flowId' => $flow->id,
        'editing' => $editing,
        'saveUrl' => $editing ? route('bots.update', $flow) : route('bots.store'),
        'method' => $editing ? 'PUT' : 'POST',
        'maxNodes' => (int) config('whatsapp.bot.max_nodes', 40),
        'triggers' => \App\Models\BotFlow::TRIGGERS,
        'matches' => \App\Models\BotFlow::MATCHES,
    ];
    $palette = ['text', 'menu', 'image', 'document', 'handoff', 'goto'];
    $icons = ['bolt', 'chat', 'menu', 'photo', 'paperclip', 'user', 'arrow-up-right', 'trash', 'copy', 'plus', 'x', 'chevron-down', 'warning'];
@endphp
<x-app-layout :title="$editing ? 'Edit bot flow' : 'New bot flow'" :full="true">
    <div class="flex h-full min-h-0 flex-col" x-data="botBuilder(@js($cfg))" @keydown.window="onKey($event)">
        {{-- Icon sprite for nodes rendered by Alpine --}}
        <div hidden>@foreach ($icons as $ic)<span data-icon="{{ $ic }}"><x-icon :name="$ic" class="h-4 w-4" /></span>@endforeach</div>

        {{-- ================================================ Top bar --}}
        <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 bg-white px-3 py-2 dark:border-slate-800 dark:bg-slate-900 sm:px-4">
            <a href="{{ route('bots.index', ['account_id' => $flow->whatsapp_account_id]) }}" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" title="Back to bot flows" aria-label="Back to bot flows">
                <x-icon name="arrow-left" class="h-5 w-5" />
            </a>
            <input type="text" x-model="s.name" maxlength="120" placeholder="Flow name, e.g. Main menu" aria-label="Flow name"
                   class="min-w-0 flex-1 rounded-lg border-transparent bg-transparent px-2 py-1.5 text-base font-semibold text-slate-900 hover:border-slate-200 focus:border-brand-500 focus:ring-brand-500 dark:text-white dark:hover:border-slate-700 sm:max-w-xs">
            <button type="button" class="hidden items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium sm:inline-flex" :class="tone('trigger')" @click="select(nodes.find(n => n.type === 'trigger').id)">
                <span x-html="iconSvg('bolt')"></span><span x-text="triggers[s.trigger_type]"></span>
            </button>

            <div class="ml-auto flex items-center gap-2">
                <span class="hidden text-xs sm:inline" :class="dirty ? 'text-amber-600 dark:text-amber-400' : 'text-slate-400'" x-text="dirty ? 'Unsaved changes' : 'All changes saved'"></span>
                <label class="flex cursor-pointer items-center gap-2 text-xs font-medium text-slate-600 dark:text-slate-300">
                    <span class="relative inline-flex">
                        <input type="checkbox" class="peer sr-only" x-model="s.is_active">
                        <span class="h-5 w-9 rounded-full bg-slate-300 transition peer-checked:bg-emerald-500 dark:bg-slate-700"></span>
                        <span class="pointer-events-none absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-white shadow transition peer-checked:translate-x-4"></span>
                    </span>
                    <span x-text="s.is_active ? 'Active' : 'Off'"></span>
                </label>
                <button type="button" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 md:hidden" @click="paletteOpen = !paletteOpen">
                    <x-icon name="plus" class="h-4 w-4" /> Add
                </button>
                <x-button type="button" icon="check" x-on:click="save()" x-bind:disabled="saving" title="Save (Ctrl+S)">
                    <span x-show="saving" x-cloak class="spinner"></span> Save
                </x-button>
            </div>
        </div>

        <div class="relative flex min-h-0 flex-1">
            {{-- ================================================ Palette --}}
            <aside class="w-60 shrink-0 flex-col overflow-y-auto border-r border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900"
                   :class="paletteOpen ? 'absolute inset-y-0 left-0 z-30 flex shadow-xl' : 'hidden md:flex'">
                <p class="px-1 pb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">Nodes — drag or click</p>
                <div class="space-y-2">
                    <template x-for="type in @js($palette)" :key="type">
                        <button type="button" draggable="true" :aria-label="`Add ${meta[type].label} node`"
                                @dragstart="$event.dataTransfer.setData('text/bot-node', type); $event.dataTransfer.effectAllowed = 'copy'"
                                @click="addNode(type)"
                                class="flex w-full items-start gap-3 rounded-xl border border-slate-200 bg-white p-2.5 text-left transition hover:border-brand-400 hover:shadow-sm active:cursor-grabbing dark:border-slate-700 dark:bg-slate-800/60 dark:hover:border-brand-500">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg" :class="tone(type)" x-html="iconSvg(meta[type].icon)"></span>
                            <span class="min-w-0">
                                <span class="block text-sm font-medium text-slate-800 dark:text-slate-100" x-text="meta[type].label"></span>
                                <span class="block text-[11px] leading-4 text-slate-500" x-text="meta[type].hint"></span>
                            </span>
                        </button>
                    </template>
                </div>
                <div class="mt-auto space-y-1 pt-5 text-[11px] leading-4 text-slate-400">
                    <p><strong class="text-slate-500">Connect:</strong> drag from a node's right dot to another node.</p>
                    <p><strong class="text-slate-500">Move:</strong> drag the canvas · scroll to zoom.</p>
                    <p><strong class="text-slate-500">Delete:</strong> select a node or line → Delete key.</p>
                </div>
            </aside>

            {{-- ================================================ Canvas --}}
            <div x-ref="canvas"
                 class="relative min-w-0 flex-1 touch-none select-none overflow-hidden bg-slate-50 [background-image:radial-gradient(circle,rgb(148_163_184/0.45)_1px,transparent_1px)] dark:bg-slate-950 dark:[background-image:radial-gradient(circle,rgb(71_85_105/0.6)_1px,transparent_1px)]"
                 :class="panning ? 'cursor-grabbing' : 'cursor-grab'"
                 :style="gridStyle()"
                 @pointerdown="startPan($event)"
                 @wheel.prevent="onWheel($event)"
                 @dragover.prevent
                 @drop.prevent="onDrop($event)">

                <div class="absolute left-0 top-0 origin-top-left" :style="`transform: translate(${pan.x}px, ${pan.y}px) scale(${zoom})`">
                    <svg class="absolute left-0 top-0 overflow-visible" width="1" height="1" style="pointer-events: none">
                        <g x-html="edgesSvg()" style="pointer-events: auto"></g>
                    </svg>

                    {{-- Delete button on the selected connection --}}
                    <template x-if="edgeMid()">
                        <button type="button" class="absolute flex h-7 w-7 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border border-slate-200 bg-white text-rose-600 shadow hover:bg-rose-50 dark:border-slate-700 dark:bg-slate-800"
                                :style="`left:${edgeMid().x}px; top:${edgeMid().y}px`" @pointerdown.stop @click="deleteEdge()" title="Remove connection" x-html="iconSvg('trash')"></button>
                    </template>

                    {{-- Nodes --}}
                    <template x-for="node in nodes" :key="node.id">
                        <div class="absolute w-[260px] cursor-default rounded-2xl border bg-white shadow-sm transition-shadow dark:bg-slate-900"
                             :class="hasError(node) ? 'border-rose-500 ring-2 ring-rose-500/30' : (selected === node.id ? 'border-brand-500 ring-2 ring-brand-500/30 shadow-lg' : 'border-slate-200 hover:shadow-md dark:border-slate-700')"
                             :style="`left:${node.x}px; top:${node.y}px`"
                             :data-node-id="node.id"
                             @pointerdown.stop="startDrag($event, node)">
                            {{-- header (44px) --}}
                            <div class="flex h-11 cursor-move items-center gap-2 rounded-t-2xl px-3" :class="tone(node.type)">
                                <span x-html="iconSvg(meta[node.type].icon)"></span>
                                <span class="truncate text-sm font-semibold" x-text="title(node)"></span>
                                <span x-show="node.type === 'menu'" class="ml-auto rounded-full bg-white/70 px-1.5 text-[10px] font-semibold dark:bg-black/20" x-text="node.data.options?.length > 3 ? 'list' : 'buttons'"></span>
                            </div>
                            {{-- body (52px) --}}
                            <div class="h-[52px] overflow-hidden border-t border-slate-100 px-3 py-2 dark:border-slate-800">
                                <p class="line-clamp-2 break-words text-xs leading-[18px] text-slate-600 dark:text-slate-400" x-text="preview(node)"></p>
                            </div>
                            {{-- menu options (34px each), one output per option --}}
                            <template x-if="node.type === 'menu'">
                                <div>
                                    <template x-for="o in node.data.options" :key="o.id">
                                        <div class="relative flex h-[34px] items-center border-t border-slate-100 px-3 text-xs font-medium text-slate-700 dark:border-slate-800 dark:text-slate-200">
                                            <span class="truncate pr-3" x-text="o.title || 'Option'"></span>
                                            <span class="absolute -right-[8px] top-1/2 h-4 w-4 -translate-y-1/2 cursor-crosshair rounded-full border-2 border-white shadow ring-1 dark:border-slate-900"
                                                  :class="isConnected(node.id, o.id) ? 'bg-violet-500 ring-violet-300' : 'bg-white ring-rose-400 dark:bg-slate-700'"
                                                  @pointerdown.stop.prevent="startLink($event, node, o.id)" title="Drag to connect this option"></span>
                                        </div>
                                    </template>
                                </div>
                            </template>
                            {{-- input dot --}}
                            <span x-show="node.type !== 'trigger'" class="absolute -left-[8px] top-[15px] h-4 w-4 rounded-full border-2 border-white shadow ring-1 ring-slate-300 dark:border-slate-900 dark:ring-slate-600"
                                  :class="linking && linking.from !== node.id ? 'scale-125 bg-brand-500' : 'bg-slate-400'"></span>
                            {{-- output dot --}}
                            <span x-show="hasOut(node)" class="absolute -right-[8px] top-[15px] h-4 w-4 cursor-crosshair rounded-full border-2 border-white shadow ring-1 dark:border-slate-900"
                                  :class="isConnected(node.id, 'out') ? 'bg-brand-500 ring-brand-300' : 'bg-white ring-slate-400 dark:bg-slate-700'"
                                  @pointerdown.stop.prevent="startLink($event, node, 'out')" title="Drag to the next node"></span>
                        </div>
                    </template>
                </div>

                {{-- Zoom controls --}}
                <div class="absolute bottom-4 left-4 flex items-center gap-1 rounded-xl border border-slate-200 bg-white p-1 shadow-sm dark:border-slate-700 dark:bg-slate-900" @pointerdown.stop>
                    <button type="button" class="rounded-lg px-2.5 py-1 text-lg leading-none text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" @click="zoomBy(1 / 1.2)" aria-label="Zoom out">−</button>
                    <span class="w-12 text-center text-xs tabular-nums text-slate-500" x-text="Math.round(zoom * 100) + '%'"></span>
                    <button type="button" class="rounded-lg px-2.5 py-1 text-lg leading-none text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" @click="zoomBy(1.2)" aria-label="Zoom in">+</button>
                    <button type="button" class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" @click="fit()">Fit</button>
                </div>

                {{-- Errors from the last save --}}
                <div x-show="errors.length" x-cloak class="absolute bottom-4 left-1/2 z-20 w-[min(92%,34rem)] -translate-x-1/2 rounded-2xl border border-rose-200 bg-white p-4 shadow-lg dark:border-rose-900 dark:bg-slate-900" @pointerdown.stop>
                    <div class="flex items-start gap-3">
                        <span class="text-rose-600" x-html="iconSvg('warning')"></span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-rose-700 dark:text-rose-300">Fix these before saving</p>
                            <ul class="mt-1 max-h-32 list-disc space-y-0.5 overflow-y-auto pl-4 text-xs text-slate-600 dark:text-slate-300">
                                <template x-for="msg in errors" :key="msg"><li x-text="msg"></li></template>
                            </ul>
                        </div>
                        <button type="button" class="text-slate-400 hover:text-slate-600" @click="errors = []" aria-label="Close" x-html="iconSvg('x')"></button>
                    </div>
                </div>
            </div>

            {{-- ================================================ Settings panel --}}
            <aside x-show="current" x-cloak x-transition.opacity
                   class="absolute inset-y-0 right-0 z-20 flex w-[22rem] max-w-[92vw] shrink-0 flex-col border-l border-slate-200 bg-white shadow-xl dark:border-slate-800 dark:bg-slate-900 lg:static lg:max-w-none lg:shadow-none">
                <template x-if="current">
                    <div class="flex min-h-0 flex-1 flex-col">
                        <div class="flex items-center gap-2 border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                            <span class="flex h-8 w-8 items-center justify-center rounded-lg" :class="tone(current.type)" x-html="iconSvg(meta[current.type].icon)"></span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-semibold text-slate-900 dark:text-white" x-text="title(current)"></p>
                                <p class="truncate text-[11px] text-slate-500" x-text="meta[current.type].hint"></p>
                            </div>
                            <template x-if="current.type !== 'trigger'">
                                <div class="flex">
                                    <button type="button" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800" @click="duplicate()" title="Duplicate" x-html="iconSvg('copy')"></button>
                                    <button type="button" class="rounded-lg p-1.5 text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-500/10" @click="removeNode()" title="Delete node" x-html="iconSvg('trash')"></button>
                                </div>
                            </template>
                            <button type="button" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800" @click="selected = null" aria-label="Close" x-html="iconSvg('x')"></button>
                        </div>

                        <div class="min-h-0 flex-1 space-y-4 overflow-y-auto p-4 text-sm">
                            {{-- Trigger = flow settings --}}
                            <template x-if="current.type === 'trigger'">
                                <div class="space-y-4">
                                    <div>
                                        <label class="form-label">Flow name</label>
                                        <input type="text" class="form-input" x-model="s.name" maxlength="120">
                                    </div>
                                    @unless ($editing)
                                        <div>
                                            <label class="form-label">WhatsApp number</label>
                                            <select class="form-input" x-model.number="s.whatsapp_account_id">
                                                <template x-for="a in accounts" :key="a.id"><option :value="a.id" x-text="a.label" :selected="a.id === s.whatsapp_account_id"></option></template>
                                            </select>
                                        </div>
                                    @endunless
                                    <div>
                                        <label class="form-label">When does it run?</label>
                                        <div class="space-y-1.5">
                                            @foreach (\App\Models\BotFlow::TRIGGERS as $value => $label)
                                                <label class="flex cursor-pointer items-center gap-2 rounded-xl border px-3 py-2" :class="s.trigger_type === '{{ $value }}' ? 'border-brand-500 bg-brand-50 dark:bg-brand-500/10' : 'border-slate-200 dark:border-slate-700'">
                                                    <input type="radio" value="{{ $value }}" x-model="s.trigger_type" class="text-brand-600 focus:ring-brand-500"> {{ $label }}
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>
                                    <div x-show="s.trigger_type === 'keyword'" class="space-y-3">
                                        <div>
                                            <label class="form-label">Keywords <span class="font-normal text-slate-400">(comma separated)</span></label>
                                            <input type="text" class="form-input" x-model="s.keywords" maxlength="1000" placeholder="price, rates, plans">
                                        </div>
                                        <div>
                                            <label class="form-label">Match</label>
                                            <select class="form-input" x-model="s.match_type">
                                                @foreach (\App\Models\BotFlow::MATCHES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                                            </select>
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="form-label">Priority</label>
                                            <input type="number" min="0" max="1000" class="form-input" x-model.number="s.priority">
                                        </div>
                                        <div>
                                            <label class="form-label">Cooldown (min)</label>
                                            <input type="number" min="0" max="10080" class="form-input" x-model.number="s.cooldown_minutes">
                                        </div>
                                    </div>
                                    <p class="form-help">Higher priority keyword flows are checked first. Cooldown stops the same flow repeating in one chat. Menu taps always answer.</p>
                                </div>
                            </template>

                            {{-- Message --}}
                            <template x-if="current.type === 'text'">
                                <div>
                                    <label class="form-label">Message</label>
                                    <textarea rows="7" class="form-input" x-model="current.data.text" maxlength="4096" placeholder="Hi! How can we help you today?"></textarea>
                                    <p class="form-help"><span x-text="(current.data.text || '').length"></span>/4096 · *bold* _italic_ and links work.</p>
                                </div>
                            </template>

                            {{-- Menu --}}
                            <template x-if="current.type === 'menu'">
                                <div class="space-y-4">
                                    <div>
                                        <label class="form-label">Menu message</label>
                                        <textarea rows="4" class="form-input" x-model="current.data.text" maxlength="1024" placeholder="Please choose an option:"></textarea>
                                    </div>
                                    <div>
                                        <div class="mb-1.5 flex items-center justify-between">
                                            <span class="form-label !mb-0">Options</span>
                                            <span class="text-[11px] text-slate-500" x-text="current.data.options.length <= 3 ? 'Reply buttons (max 20 chars)' : 'List (max 24 chars)'"></span>
                                        </div>
                                        <div class="space-y-2">
                                            <template x-for="(o, i) in current.data.options" :key="o.id">
                                                <div class="rounded-xl border border-slate-200 p-2 dark:border-slate-700">
                                                    <div class="flex items-center gap-1">
                                                        <input type="text" class="form-input !py-1.5" x-model="o.title" :maxlength="current.data.options.length <= 3 ? 20 : 24" placeholder="Option title">
                                                        <button type="button" class="rounded p-1 text-slate-400 hover:text-slate-700 disabled:opacity-30" @click="moveOption(i, -1)" :disabled="i === 0" aria-label="Move up"><span class="block rotate-180" x-html="iconSvg('chevron-down')"></span></button>
                                                        <button type="button" class="rounded p-1 text-slate-400 hover:text-slate-700 disabled:opacity-30" @click="moveOption(i, 1)" :disabled="i === current.data.options.length - 1" aria-label="Move down"><span x-html="iconSvg('chevron-down')"></span></button>
                                                        <button type="button" class="rounded p-1 text-rose-500 disabled:opacity-30" @click="removeOption(i)" :disabled="current.data.options.length <= 1" aria-label="Remove option"><span x-html="iconSvg('trash')"></span></button>
                                                    </div>
                                                    <input type="text" x-show="current.data.options.length > 3" class="form-input mt-1.5 !py-1.5 text-xs" x-model="o.description" maxlength="72" placeholder="Description (optional)">
                                                    <p class="mt-1 text-[11px]" :class="isConnected(current.id, o.id) ? 'text-emerald-600' : 'text-rose-500'"
                                                       x-text="isConnected(current.id, o.id) ? '→ ' + title(node(edges.find(e => e.from === current.id && e.port === o.id).to)) : 'Not connected — drag from its dot on the canvas'"></p>
                                                </div>
                                            </template>
                                        </div>
                                        <button type="button" class="mt-2 inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-medium text-brand-700 hover:bg-brand-50 disabled:opacity-40 dark:text-brand-400 dark:hover:bg-brand-500/10" @click="addOption()" :disabled="current.data.options.length >= 10">
                                            <span x-html="iconSvg('plus')"></span> Add option
                                        </button>
                                    </div>
                                    <div>
                                        <label class="form-label">Footer <span class="font-normal text-slate-400">(optional)</span></label>
                                        <input type="text" class="form-input" x-model="current.data.footer" maxlength="60">
                                    </div>
                                    <div x-show="current.data.options.length > 3">
                                        <label class="form-label">List button label</label>
                                        <input type="text" class="form-input" x-model="current.data.button_label" maxlength="20" placeholder="Choose">
                                    </div>
                                </div>
                            </template>

                            {{-- Image / Document --}}
                            <template x-if="current.type === 'image' || current.type === 'document'">
                                <div class="space-y-4">
                                    <div>
                                        <label class="form-label" x-text="current.type === 'image' ? 'Image link (https)' : 'Document link (https)'"></label>
                                        <input type="url" class="form-input" x-model="current.data.url" maxlength="2000" placeholder="https://example.com/file">
                                        <p class="form-help">Must be a public https:// link that WhatsApp can download.</p>
                                    </div>
                                    <template x-if="current.type === 'image' && current.data.url && current.data.url.startsWith('https://')">
                                        <img :src="current.data.url" alt="" class="max-h-40 rounded-xl border border-slate-200 object-contain dark:border-slate-700">
                                    </template>
                                    <div x-show="current.type === 'document'">
                                        <label class="form-label">File name shown to the customer</label>
                                        <input type="text" class="form-input" x-model="current.data.filename" maxlength="120" placeholder="Price-list.pdf">
                                    </div>
                                    <div>
                                        <label class="form-label">Caption <span class="font-normal text-slate-400">(optional)</span></label>
                                        <textarea rows="3" class="form-input" x-model="current.data.caption" maxlength="1024"></textarea>
                                    </div>
                                </div>
                            </template>

                            {{-- Handoff --}}
                            <template x-if="current.type === 'handoff'">
                                <div>
                                    <label class="form-label">Message before handing over <span class="font-normal text-slate-400">(optional)</span></label>
                                    <textarea rows="4" class="form-input" x-model="current.data.text" maxlength="1024"></textarea>
                                    <p class="form-help">The bot then stays quiet in this chat for {{ config('whatsapp.bot.handoff_hours') }} hours so your team can reply. This ends the flow.</p>
                                </div>
                            </template>

                            {{-- Go to flow --}}
                            <template x-if="current.type === 'goto'">
                                <div>
                                    <label class="form-label">Continue in flow</label>
                                    <select class="form-input" x-model.number="current.data.flow_id">
                                        <option value="">Choose a flow…</option>
                                        <template x-for="f in gotoTargets()" :key="f.id"><option :value="f.id" x-text="f.name" :selected="f.id === Number(current.data.flow_id)"></option></template>
                                    </select>
                                    <p class="form-help" x-show="gotoTargets().length === 0">Create and save another flow for this number first (e.g. a “Menu only” flow).</p>
                                    <p class="form-help" x-show="gotoTargets().length">The other flow starts from its Trigger. Tip: “Menu only” flows are perfect for this.</p>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </aside>
        </div>
    </div>
</x-app-layout>
