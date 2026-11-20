@props(['path', 'type' => 'image', 'label', 'help' => null])
{{-- Image / video picker: upload, choose from the media library or paste an https:// link. --}}
<div>
    <span class="form-label">{{ $label }}</span>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
        <div class="flex h-28 w-full shrink-0 items-center justify-center overflow-hidden rounded-xl border border-dashed border-slate-300 bg-slate-50 dark:border-slate-700 dark:bg-slate-800/60 sm:w-44">
            <template x-if="get('{{ $path }}')">
                @if ($type === 'video')
                    <video x-bind:src="url(get('{{ $path }}'))" class="h-full w-full bg-black object-cover" muted preload="metadata"></video>
                @else
                    <img x-bind:src="url(get('{{ $path }}'))" class="h-full w-full object-contain" alt="">
                @endif
            </template>
            <template x-if="!get('{{ $path }}')">
                <x-icon :name="$type === 'video' ? 'paperclip' : 'photo'" class="h-8 w-8 text-slate-300 dark:text-slate-600" />
            </template>
        </div>
        <div class="min-w-0 flex-1 space-y-2">
            <input type="text" class="form-input" x-bind:value="get('{{ $path }}')" x-on:change="set('{{ $path }}', $event.target.value.trim())" placeholder="Upload a file or paste an https:// link" aria-label="{{ $label }} link">
            <div class="flex flex-wrap gap-2">
                <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-xl bg-brand-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-brand-700">
                    <span x-show="uploading !== '{{ $path }}'"><x-icon name="upload" class="h-4 w-4" /></span>
                    <span x-show="uploading === '{{ $path }}'" x-cloak class="spinner"></span>
                    <span x-text="uploading === '{{ $path }}' ? 'Uploading…' : 'Upload {{ $type }}'">Upload {{ $type }}</span>
                    <input type="file" class="sr-only" accept="{{ $type === 'video' ? 'video/mp4,video/webm' : 'image/png,image/jpeg,image/webp,image/gif' }}" x-on:change="upload($event, '{{ $path }}', '{{ $type }}')" x-bind:disabled="uploading !== null">
                </label>
                <button type="button" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700" x-on:click="openLibrary('{{ $path }}', '{{ $type }}')">
                    <x-icon name="photo" class="h-4 w-4" /> Media library
                </button>
                <button type="button" x-show="get('{{ $path }}')" x-cloak class="inline-flex items-center gap-1.5 rounded-xl px-3 py-1.5 text-xs font-medium text-rose-600 hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-500/10" x-on:click="set('{{ $path }}', '')">
                    <x-icon name="x" class="h-4 w-4" /> Remove
                </button>
            </div>
            @if ($help)<p class="form-help">{{ $help }}</p>@endif
        </div>
    </div>
</div>
