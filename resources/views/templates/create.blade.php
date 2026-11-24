@php
    $init = [
        'name' => old('name', ''),
        'category' => old('category', 'MARKETING'),
        'header_type' => old('header_type', 'NONE'),
        'header_text' => old('header_text', ''),
        'header_example' => old('header_example', ''),
        'body' => old('body', ''),
        'body_examples' => old('body_examples', []),
        'footer' => old('footer', ''),
        'buttons' => array_values(old('buttons', [])),
        'auth_expiry' => (int) old('auth_expiry', 10),
    ];
@endphp
<x-app-layout title="New template">
    <div class="mx-auto max-w-6xl" x-data="{
        ...@js($init),
        saving: false,
        get vars() { return [...new Set([...(this.body.matchAll(/\{\{(\d+)\}\}/g))].map(m => +m[1]))].sort((a, b) => a - b) },
        get headerHasVar() { return /\{\{1\}\}/.test(this.header_text) },
        slug(v) { return v.toLowerCase().replace(/[^a-z0-9_]+/g, '_').replace(/^_+/, '').slice(0, 512) },
        addVar() { const n = (this.vars.at(-1) || 0) + 1; this.body = (this.body + ' ' + '{' + '{' + n + '}' + '}').trimStart(); },
        addButton(type) { if (this.buttons.length < 10) this.buttons.push({ type, text: '', url: '', example: '', phone: '' }) },
        fill(text) { return text.replace(/\{\{(\d+)\}\}/g, (m, n) => this.body_examples[n] || m) },
    }">
        <x-page-header title="New message template" description="Write the template, then submit it — Meta reviews it (usually within minutes) before you can use it.">
            <x-button variant="secondary" icon="arrow-left" :href="route('templates.index', ['account_id' => $account?->id])">Templates</x-button>
        </x-page-header>

        @if ($errors->any())
            <div class="mb-6 rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm text-rose-700 dark:border-rose-900 dark:bg-rose-500/10 dark:text-rose-300">
                <ul class="list-disc space-y-1 pl-5">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        <form method="POST" action="{{ route('templates.store') }}" enctype="multipart/form-data" @submit="saving = true" class="grid gap-6 lg:grid-cols-5">
            @csrf
            <div class="space-y-6 lg:col-span-3">
                <x-card title="1. Basics">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label class="form-label" for="whatsapp_account_id">WhatsApp number</label>
                            <select id="whatsapp_account_id" name="whatsapp_account_id" class="form-input">
                                @foreach ($accounts as $a)
                                    <option value="{{ $a->id }}" @selected((int) old('whatsapp_account_id', $account?->id) === $a->id)>{{ $a->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="form-label" for="name">Template name</label>
                            <input id="name" name="name" class="form-input font-mono" x-model="name" @input="name = slug($event.target.value)" required maxlength="512" placeholder="order_update">
                            <p class="form-help">Small letters, numbers and _ only.</p>
                        </div>
                        <div>
                            <label class="form-label" for="language">Language</label>
                            <select id="language" name="language" class="form-input">
                                @foreach ($languages as $code => $label)
                                    <option value="{{ $code }}" @selected(old('language', 'en') === $code)>{{ $label }} ({{ $code }})</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="mt-4">
                        <span class="form-label">Category</span>
                        <div class="grid gap-2 sm:grid-cols-3">
                            @foreach (['MARKETING' => ['Marketing', 'Offers, news, promotions'], 'UTILITY' => ['Utility', 'Order / booking / account updates'], 'AUTHENTICATION' => ['Authentication', 'One-time passwords (OTP)']] as $value => [$label, $help])
                                <label class="flex cursor-pointer gap-2 rounded-xl border p-3" :class="category === '{{ $value }}' ? 'border-brand-500 bg-brand-50 dark:bg-brand-500/10' : 'border-slate-200 dark:border-slate-700'">
                                    <input type="radio" name="category" value="{{ $value }}" x-model="category" class="mt-0.5 text-brand-600 focus:ring-brand-500">
                                    <span><span class="block text-sm font-medium">{{ $label }}</span><span class="text-xs text-slate-500">{{ $help }}</span></span>
                                </label>
                            @endforeach
                        </div>
                        <p class="form-help">Meta may change the category after review. Marketing messages cost the most.</p>
                    </div>
                </x-card>

                {{-- Authentication templates have a fixed format --}}
                <x-card title="2. OTP settings" x-show="category === 'AUTHENTICATION'" x-cloak>
                    <p class="text-sm text-slate-600 dark:text-slate-400">Meta writes the text for you: “<strong>123456</strong> is your verification code.” with a <em>Copy code</em> button.</p>
                    <label class="mt-4 flex items-center gap-2 text-sm"><input type="hidden" name="auth_security" value="0"><input type="checkbox" name="auth_security" value="1" checked class="rounded border-slate-300 text-brand-600"> Add “For your security, do not share this code.”</label>
                    <div class="mt-4 max-w-xs">
                        <label class="form-label" for="auth_expiry">Code expires after (minutes)</label>
                        <input id="auth_expiry" name="auth_expiry" type="number" min="1" max="90" class="form-input" x-model.number="auth_expiry">
                    </div>
                </x-card>

                <div x-show="category !== 'AUTHENTICATION'" class="space-y-6">
                    <x-card title="2. Header (optional)">
                        <div class="flex flex-wrap gap-2">
                            @foreach (['NONE' => 'None', 'TEXT' => 'Text', 'IMAGE' => 'Image', 'VIDEO' => 'Video', 'DOCUMENT' => 'Document'] as $value => $label)
                                <label class="cursor-pointer rounded-xl border px-3 py-1.5 text-sm" :class="header_type === '{{ $value }}' ? 'border-brand-500 bg-brand-50 text-brand-800 dark:bg-brand-500/10 dark:text-brand-200' : 'border-slate-200 dark:border-slate-700'">
                                    <input type="radio" name="header_type" value="{{ $value }}" x-model="header_type" class="sr-only">{{ $label }}
                                </label>
                            @endforeach
                        </div>
                        <div x-show="header_type === 'TEXT'" class="mt-4 grid gap-3 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <input name="header_text" x-model="header_text" maxlength="60" class="form-input" placeholder="Your order is on the way">
                                <p class="form-help">Max 60 characters, at most one variable {{ '{' }}{{ '{' }}1}}.</p>
                            </div>
                            <div x-show="headerHasVar"><input name="header_example" x-model="header_example" maxlength="60" class="form-input" placeholder="Example for {{ '{' }}{{ '{' }}1}}"></div>
                        </div>
                        <div x-show="['IMAGE', 'VIDEO', 'DOCUMENT'].includes(header_type)" x-cloak class="mt-4">
                            <input type="file" name="header_file" class="block text-sm" :accept="{ IMAGE: 'image/jpeg,image/png', VIDEO: 'video/mp4', DOCUMENT: 'application/pdf' }[header_type]">
                            <p class="form-help">A sample for Meta's review. When sending you can use a different file.</p>
                        </div>
                    </x-card>

                    <x-card title="3. Message">
                        <textarea name="body" rows="6" maxlength="1024" class="form-input" x-model="body" placeholder="Hi {{ '{' }}{{ '{' }}1}}, your order {{ '{' }}{{ '{' }}2}} has been shipped and will arrive {{ '{' }}{{ '{' }}3}}."></textarea>
                        <div class="mt-2 flex flex-wrap items-center gap-3">
                            <button type="button" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-medium text-brand-700 hover:bg-brand-50 dark:text-brand-400 dark:hover:bg-brand-500/10" @click="addVar()"><x-icon name="plus" class="h-4 w-4" /> Add variable</button>
                            <span class="text-xs text-slate-500"><span x-text="body.length"></span>/1024 · *bold* _italic_ ~strike~</span>
                        </div>
                        <template x-if="vars.length">
                            <div class="mt-4 grid gap-2 sm:grid-cols-2">
                                <p class="text-xs font-medium text-slate-600 sm:col-span-2 dark:text-slate-300">Examples (required by Meta for review)</p>
                                <template x-for="n in vars" :key="n">
                                    <label class="flex items-center gap-2 text-xs">
                                        <span class="w-10 font-mono text-slate-500" x-text="'{' + '{' + n + '}' + '}'"></span>
                                        <input class="form-input !py-1.5" :name="'body_examples[' + n + ']'" x-model="body_examples[n]" maxlength="200" placeholder="e.g. Ali">
                                    </label>
                                </template>
                            </div>
                        </template>
                    </x-card>

                    <x-card title="4. Footer & buttons (optional)">
                        <input name="footer" x-model="footer" maxlength="60" class="form-input" placeholder="Reply STOP to unsubscribe">
                        <div class="mt-4 space-y-2">
                            <template x-for="(b, i) in buttons" :key="i">
                                <div class="grid gap-2 rounded-xl border border-slate-200 p-3 dark:border-slate-700 sm:grid-cols-12">
                                    <input type="hidden" :name="'buttons[' + i + '][type]'" :value="b.type">
                                    <span class="self-center text-xs font-semibold text-slate-500 sm:col-span-2" x-text="{ QUICK_REPLY: 'Quick reply', URL: 'Link', PHONE_NUMBER: 'Call' }[b.type]"></span>
                                    <input class="form-input !py-1.5 sm:col-span-3" :name="'buttons[' + i + '][text]'" x-model="b.text" maxlength="25" placeholder="Button text" required>
                                    <template x-if="b.type === 'URL'">
                                        <div class="grid gap-2 sm:col-span-6 sm:grid-cols-2">
                                            <input class="form-input !py-1.5" :name="'buttons[' + i + '][url]'" x-model="b.url" placeholder="https://site.com/track/{{ '{' }}{{ '{' }}1}}">
                                            <input class="form-input !py-1.5" :name="'buttons[' + i + '][example]'" x-model="b.example" placeholder="Example full link" x-show="b.url.includes('{' + '{')">
                                        </div>
                                    </template>
                                    <template x-if="b.type === 'PHONE_NUMBER'">
                                        <input class="form-input !py-1.5 sm:col-span-6" :name="'buttons[' + i + '][phone]'" x-model="b.phone" placeholder="+923001234567">
                                    </template>
                                    <template x-if="b.type === 'QUICK_REPLY'"><span class="sm:col-span-6"></span></template>
                                    <button type="button" class="justify-self-end rounded p-1 text-rose-500 hover:bg-rose-50 sm:col-span-1 dark:hover:bg-rose-500/10" @click="buttons.splice(i, 1)" aria-label="Remove button"><x-icon name="trash" class="h-4 w-4" /></button>
                                </div>
                            </template>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-2 text-xs">
                            <button type="button" class="rounded-lg border border-slate-300 px-2.5 py-1.5 hover:bg-slate-50 disabled:opacity-40 dark:border-slate-700 dark:hover:bg-slate-800" @click="addButton('QUICK_REPLY')" :disabled="buttons.length >= 10">+ Quick reply</button>
                            <button type="button" class="rounded-lg border border-slate-300 px-2.5 py-1.5 hover:bg-slate-50 disabled:opacity-40 dark:border-slate-700 dark:hover:bg-slate-800" @click="addButton('URL')" :disabled="buttons.filter(b => b.type === 'URL').length >= 2">+ Link button</button>
                            <button type="button" class="rounded-lg border border-slate-300 px-2.5 py-1.5 hover:bg-slate-50 disabled:opacity-40 dark:border-slate-700 dark:hover:bg-slate-800" @click="addButton('PHONE_NUMBER')" :disabled="buttons.some(b => b.type === 'PHONE_NUMBER')">+ Call button</button>
                        </div>
                    </x-card>
                </div>

                <div class="flex justify-end">
                    <x-button ::disabled="saving"><span x-show="saving" x-cloak class="spinner"></span> Submit to Meta for review</x-button>
                </div>
            </div>

            {{-- Live preview --}}
            <div class="lg:col-span-2">
                <div class="sticky top-20">
                    <p class="mb-2 text-sm font-medium text-slate-600 dark:text-slate-300">Preview</p>
                    <div class="chat-wallpaper rounded-2xl p-4">
                        <div class="max-w-[90%] overflow-hidden rounded-xl rounded-tl-sm bg-white text-[13px] leading-snug text-slate-800 shadow-sm dark:bg-chat-inDark dark:text-slate-100">
                            <template x-if="category === 'AUTHENTICATION'">
                                <div class="px-3 py-2">
                                    <p><strong>123456</strong> is your verification code. <span x-show="true">For your security, do not share this code.</span></p>
                                    <p class="mt-1 text-[11px] text-slate-500" x-text="'This code expires in ' + auth_expiry + ' minutes.'"></p>
                                </div>
                            </template>
                            <template x-if="category !== 'AUTHENTICATION'">
                                <div>
                                    <div x-show="['IMAGE', 'VIDEO', 'DOCUMENT'].includes(header_type)" class="flex h-32 items-center justify-center bg-slate-200 text-xs text-slate-500 dark:bg-slate-700" x-text="header_type.toLowerCase()"></div>
                                    <div class="px-3 py-2">
                                        <p x-show="header_type === 'TEXT' && header_text" class="mb-1 font-semibold" x-text="header_text.replace('{' + '{1}' + '}', header_example || '{' + '{1}' + '}')"></p>
                                        <p class="whitespace-pre-line" x-text="fill(body) || 'Your message…'"></p>
                                        <p x-show="footer" class="mt-1 text-[11px] text-slate-500" x-text="footer"></p>
                                    </div>
                                </div>
                            </template>
                            <template x-for="(b, i) in (category === 'AUTHENTICATION' ? [{ type: 'OTP', text: 'Copy code' }] : buttons)" :key="'p' + i">
                                <p class="border-t border-black/5 py-2 text-center text-[13px] font-medium text-sky-600 dark:border-white/10 dark:text-sky-300" x-text="(b.type === 'URL' ? '↗ ' : b.type === 'PHONE_NUMBER' ? '✆ ' : '') + (b.text || 'Button')"></p>
                            </template>
                        </div>
                    </div>
                    <p class="mt-3 text-xs text-slate-500">Tips: no variables at the very start/end of the message, give realistic examples, and don't ask for card numbers or passwords — Meta rejects such templates.</p>
                </div>
            </div>
        </form>
    </div>
</x-app-layout>
