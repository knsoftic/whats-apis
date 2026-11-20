{{-- Built-in hero illustration (shown until an image / video is set on Settings → Landing page). Decorative only. --}}
<div class="relative" aria-hidden="true">
    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl shadow-slate-900/10 dark:border-slate-800 dark:bg-slate-900">
        <div class="flex items-center gap-1.5 border-b border-slate-200 bg-slate-50 px-4 py-2.5 dark:border-slate-800 dark:bg-slate-800/60">
            <span class="h-2.5 w-2.5 rounded-full bg-rose-400"></span><span class="h-2.5 w-2.5 rounded-full bg-amber-400"></span><span class="h-2.5 w-2.5 rounded-full bg-emerald-400"></span>
            <span class="ml-3 truncate rounded-md bg-white px-3 py-0.5 text-[11px] text-slate-400 dark:bg-slate-900">{{ parse_url(url('/'), PHP_URL_HOST) }}/chat</span>
        </div>
        <div class="grid h-80 grid-cols-5 sm:h-96">
            <div class="col-span-2 hidden border-r border-slate-200 dark:border-slate-800 sm:block">
                <div class="border-b border-slate-200 p-3 dark:border-slate-800"><div class="h-7 rounded-lg bg-slate-100 dark:bg-slate-800"></div></div>
                @foreach ([['Ayesha Khan', 'Is my order shipped?', '2', 'bg-rose-400'], ['Bilal Ahmed', 'Thanks! 👍', null, 'bg-sky-400'], ['Sara Malik', 'Book for Friday', '1', 'bg-violet-400'], ['Usman Ali', 'Price list please', null, 'bg-amber-400']] as $i => [$name, $msg, $unread, $color])
                    <div @class(['flex items-center gap-2.5 px-3 py-2.5', 'bg-brand-50 dark:bg-brand-950/40' => $i === 0])>
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full {{ $color }} text-[11px] font-bold text-white">{{ mb_substr($name, 0, 1) }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-xs font-semibold text-slate-800 dark:text-slate-100">{{ $name }}</span>
                            <span class="block truncate text-[11px] text-slate-500">{{ $msg }}</span>
                        </span>
                        @if ($unread)<span class="flex h-4 min-w-4 items-center justify-center rounded-full bg-brand-600 px-1 text-[10px] font-bold text-white">{{ $unread }}</span>@endif
                    </div>
                @endforeach
            </div>
            <div class="col-span-5 flex flex-col sm:col-span-3">
                <div class="flex items-center gap-2.5 border-b border-slate-200 px-4 py-2.5 dark:border-slate-800">
                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-rose-400 text-[11px] font-bold text-white">A</span>
                    <span><span class="block text-xs font-semibold text-slate-800 dark:text-slate-100">Ayesha Khan</span><span class="block text-[10px] text-emerald-600">online</span></span>
                </div>
                <div class="chat-wallpaper flex flex-1 flex-col justify-end gap-2 overflow-hidden p-4 text-[12px] leading-snug">
                    <div class="max-w-[78%] self-start rounded-xl rounded-tl-sm bg-white px-3 py-2 text-slate-800 shadow-sm dark:bg-chat-inDark dark:text-slate-100">Hi! Is my order #1042 shipped? 📦</div>
                    <div class="max-w-[78%] self-end rounded-xl rounded-tr-sm bg-chat-outLight px-3 py-2 text-slate-800 shadow-sm dark:bg-chat-outDark dark:text-slate-100">
                        Yes! It left our warehouse today and arrives tomorrow.
                        <span class="mt-0.5 flex justify-end gap-1 text-[10px] text-slate-500 dark:text-slate-300">10:24 <span class="text-sky-500">✓✓</span></span>
                    </div>
                    <div class="max-w-[78%] self-end overflow-hidden rounded-xl rounded-tr-sm bg-chat-outLight text-slate-800 shadow-sm dark:bg-chat-outDark dark:text-slate-100">
                        <p class="px-3 pt-2">Track your parcel anytime 👇</p>
                        <p class="mt-2 border-t border-black/5 py-1.5 text-center font-medium text-sky-600 dark:border-white/10 dark:text-sky-300">Track order</p>
                    </div>
                    <div class="max-w-[60%] self-start rounded-xl rounded-tl-sm bg-white px-3 py-2 text-slate-800 shadow-sm dark:bg-chat-inDark dark:text-slate-100">Perfect, thank you! 🙏</div>
                </div>
                <div class="flex items-center gap-2 border-t border-slate-200 px-3 py-2.5 dark:border-slate-800">
                    <div class="h-8 flex-1 rounded-full bg-slate-100 dark:bg-slate-800"></div>
                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-brand-600 text-white"><x-icon name="send" class="h-4 w-4" /></span>
                </div>
            </div>
        </div>
    </div>

    <div class="absolute -left-4 top-24 hidden items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-medium text-slate-700 shadow-lg dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 lg:flex">
        <span class="flex h-6 w-6 items-center justify-center rounded-lg bg-brand-100 text-brand-700 dark:bg-brand-900 dark:text-brand-300"><x-icon name="bolt" class="h-3.5 w-3.5" /></span>
        Bot replied in 1s
    </div>
    <div class="absolute -bottom-4 right-6 hidden items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-medium text-slate-700 shadow-lg dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 sm:flex">
        <span class="flex h-6 w-6 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700 dark:bg-emerald-900 dark:text-emerald-300"><x-icon name="shield" class="h-3.5 w-3.5" /></span>
        Built on Meta Cloud API
    </div>
</div>
