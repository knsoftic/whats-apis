{{-- WhatsApp-style preview of a template. Variables are highlighted. --}}
@php
    $highlight = fn (?string $text) => preg_replace('/(\{\{\s*[a-zA-Z0-9_]+\s*\}\})/', '<span class="rounded bg-amber-200/70 px-0.5 font-mono text-[12px] text-amber-900 dark:bg-amber-400/30 dark:text-amber-100">$1</span>', e((string) $text));
    $header = $template->header();
    $format = $template->headerFormat();
@endphp
<div class="chat-wallpaper rounded-xl p-4">
    <div class="max-w-full rounded-xl rounded-tl-sm bg-white p-3 text-sm text-slate-900 shadow-sm dark:bg-[#202c33] dark:text-slate-100">
        @if ($format && $format !== 'TEXT')
            <div class="mb-2 flex items-center justify-center gap-2 rounded-lg bg-slate-100 py-6 text-xs font-semibold uppercase text-slate-500 dark:bg-slate-700/50">
                <x-icon :name="$format === 'IMAGE' ? 'photo' : ($format === 'LOCATION' ? 'map-pin' : 'template')" class="h-5 w-5" /> {{ $format }} header
            </div>
        @elseif ($header)
            <p class="mb-1 font-semibold">{!! $highlight($header['text'] ?? '') !!}</p>
        @endif
        <p class="whitespace-pre-wrap">{!! $highlight($template->body()['text'] ?? '') !!}</p>
        @if ($template->footer())
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $template->footer()['text'] ?? '' }}</p>
        @endif
    </div>
    @foreach ($template->buttons() as $button)
        <div class="mt-1 flex items-center justify-center gap-1.5 rounded-lg bg-white py-1.5 text-sm font-medium text-sky-600 shadow-sm dark:bg-[#202c33] dark:text-sky-400">
            @if (strtoupper($button['type'] ?? '') === 'URL')<x-icon name="link" class="h-4 w-4" />@endif
            {{ $button['text'] ?? '' }}
        </div>
    @endforeach
</div>
