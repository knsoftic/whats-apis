@php use App\Support\Permissions as P; @endphp
<x-app-layout :title="$template->name">
    <x-page-header :title="$template->name" :description="$template->language.' · '.ucfirst(strtolower((string) $template->category)).' · '.$template->account->name">
        <x-button variant="secondary" icon="arrow-left" :href="route('templates.index', ['account_id' => $template->whatsapp_account_id])">Templates</x-button>
    </x-page-header>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-card title="Preview">
            <div class="mb-3 flex flex-wrap gap-2">
                <x-badge :color="$template->isApproved() ? 'green' : 'amber'" dot>{{ $template->status }}</x-badge>
                <x-badge>{{ $template->parameter_format }} variables</x-badge>
                @if ($template->meta_template_id)<x-badge>ID {{ $template->meta_template_id }}</x-badge>@endif
            </div>
            @include('templates.partials.preview', ['template' => $template])
        </x-card>

        @can(P::TEMPLATES_SEND)
            <x-card title="Send this template" subtitle="Starts or continues a conversation. Only send to customers who opted in.">
                @if (! $template->isApproved())
                    <p class="rounded-xl bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">Only APPROVED templates can be sent.</p>
                @else
                    <form method="POST" action="{{ route('templates.send', $template) }}" class="space-y-4" x-data="{ busy: false }" @submit="busy = true">
                        @csrf
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="form-label" for="phone">Phone (with country code)</label>
                                <input id="phone" name="phone" value="{{ old('phone') }}" class="form-input font-mono" placeholder="+91 98765 43210" required>
                                @error('phone')<p class="form-error">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="form-label" for="name">Name (optional)</label>
                                <input id="name" name="name" value="{{ old('name') }}" class="form-input">
                            </div>
                        </div>

                        @if (in_array($template->headerFormat(), ['IMAGE', 'VIDEO', 'DOCUMENT'], true))
                            <div>
                                <label class="form-label">{{ ucfirst(strtolower($template->headerFormat())) }} header — public HTTPS link</label>
                                <input name="variables[header][link]" value="{{ old('variables.header.link') }}" class="form-input" placeholder="https://…">
                                @error('header.link')<p class="form-error">{{ $message }}</p>@enderror
                                @if ($template->headerFormat() === 'DOCUMENT')
                                    <input name="variables[header][filename]" value="{{ old('variables.header.filename') }}" class="form-input mt-2" placeholder="File name shown to customer (optional)">
                                @endif
                            </div>
                        @endif
                        @foreach ($template->headerPlaceholders() as $key)
                            <div>
                                <label class="form-label">Header &#123;&#123;{{ $key }}&#125;&#125;</label>
                                <input name="variables[header][{{ $key }}]" value="{{ old('variables.header.'.$key) }}" class="form-input">
                                @error('header.'.$key)<p class="form-error">{{ $message }}</p>@enderror
                            </div>
                        @endforeach
                        @foreach ($template->bodyPlaceholders() as $key)
                            <div>
                                <label class="form-label">Body &#123;&#123;{{ $key }}&#125;&#125;</label>
                                <input name="variables[body][{{ $key }}]" value="{{ old('variables.body.'.$key) }}" class="form-input">
                                @error('body.'.$key)<p class="form-error">{{ $message }}</p>@enderror
                            </div>
                        @endforeach
                        @foreach ($template->dynamicUrlButtons() as $button)
                            <div>
                                <label class="form-label">Button “{{ $button['text'] }}” URL variable</label>
                                <input name="variables[buttons][{{ $button['index'] }}]" value="{{ old('variables.buttons.'.$button['index']) }}" class="form-input" placeholder="{{ $button['url'] }}">
                                @error('buttons.'.$button['index'])<p class="form-error">{{ $message }}</p>@enderror
                            </div>
                        @endforeach

                        <div class="flex justify-end">
                            <x-button icon="send" ::disabled="busy"><span x-show="busy" class="spinner" x-cloak></span> Send template</x-button>
                        </div>
                    </form>
                @endif
            </x-card>
        @endcan

        <x-card title="Raw components (from Meta)" class="lg:col-span-2">
            <pre class="max-h-96 overflow-auto rounded-xl bg-slate-50 p-4 text-xs dark:bg-slate-800">{{ json_encode($template->components, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
        </x-card>
    </div>
</x-app-layout>
