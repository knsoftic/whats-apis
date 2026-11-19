@php
    $routes = [
        'index' => route('chat.index'),
        'conversations' => route('chat.conversations'),
        'show' => route('chat.show', ['conversation' => '__id__']),
        'read' => route('chat.read', ['conversation' => '__id__']),
        'unread' => route('chat.unread', ['conversation' => '__id__']),
        'update' => route('chat.update', ['conversation' => '__id__']),
        'text' => route('chat.messages.text', ['conversation' => '__id__']),
        'media' => route('chat.messages.media', ['conversation' => '__id__']),
        'template' => route('chat.messages.template', ['conversation' => '__id__']),
        'retry' => route('chat.messages.retry', ['message' => '__id__']),
        'start' => route('chat.start'),
        'search' => route('chat.search'),
        'templates' => route('chat.templates'),
        'contact' => Route::has('contacts.show') ? route('contacts.show', ['contact' => '__id__']) : null,
    ];
@endphp
<x-app-layout title="Chats" :full="true">
    @if ($accounts->isEmpty())
        <div class="flex h-full items-center justify-center p-6">
            <x-empty-state icon="phone" title="No WhatsApp number available"
                           description="You don't have access to any WhatsApp account yet. Ask an administrator to add a number or assign one to you." />
        </div>
    @else
    <div x-data="chatApp(@js(['accounts' => $accounts, 'accountId' => $accountId, 'conversationId' => $conversationId, 'can' => $can, 'routes' => $routes]))"
         class="flex h-full min-h-0">

        {{-- ================================================================ LEFT: conversation list --}}
        <section :class="mobileView === 'list' ? 'flex' : 'hidden md:flex'"
                 class="w-full min-w-0 flex-col border-r border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900 md:w-[340px] md:shrink-0 xl:w-[380px]">

            {{-- Account switcher + new chat --}}
            <div class="flex items-center gap-2 border-b border-slate-200 p-3 dark:border-slate-800">
                <div class="relative min-w-0 flex-1">
                    <select class="form-input py-2 pr-8 text-sm font-medium" :value="accountId" @change="switchAccount(Number($event.target.value))" aria-label="WhatsApp number">
                        <template x-for="a in accounts" :key="a.id">
                            <option :value="a.id" :selected="a.id === accountId" x-text="a.name + (a.phone ? ' · ' + a.phone : '') + (a.active ? '' : ' (disabled)')"></option>
                        </template>
                    </select>
                </div>
                <template x-if="can.template">
                    <button type="button" @click="openTemplateModal(true)" class="rounded-xl bg-emerald-600 p-2 text-white hover:bg-emerald-700" title="New chat (template)" aria-label="New chat">
                        <x-icon name="plus" />
                    </button>
                </template>
            </div>

            {{-- Connection hint --}}
            <div class="flex items-center gap-2 px-3 pt-2 text-[11px]" :class="connected ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'">
                <span class="h-1.5 w-1.5 rounded-full" :class="connected ? 'bg-emerald-500' : 'bg-amber-500 animate-pulse'"></span>
                <span x-text="connected ? 'Live updates connected' : 'Live updates offline — refreshing every 10 s'"></span>
            </div>

            {{-- Search --}}
            <div class="space-y-2 p-3">
                <div class="relative">
                    <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <input type="search" x-model="q" class="form-input py-2 pl-9 pr-24 text-sm"
                           :placeholder="searchMode ? 'Search inside messages…' : 'Search name or number…'">
                    <button type="button" @click="toggleSearchMode()" class="absolute right-1.5 top-1/2 -translate-y-1/2 rounded-lg px-2 py-1 text-[11px] font-medium"
                            :class="searchMode ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300'"
                            x-text="searchMode ? 'Messages' : 'Contacts'" title="Switch between contact search and message search"></button>
                </div>
                <div class="flex gap-1.5" x-show="!searchMode">
                    <template x-for="f in [['all','All'],['unread','Unread'],['open','Open'],['closed','Closed']]" :key="f[0]">
                        <button type="button" @click="filter = f[0]" class="rounded-full px-3 py-1 text-xs font-medium transition"
                                :class="filter === f[0] ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-300' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-400'">
                            <span x-text="f[1]"></span><span x-show="f[0] === 'unread' && unreadTotal" x-text="' ' + unreadTotal"></span>
                        </button>
                    </template>
                </div>
            </div>

            {{-- Message search results --}}
            <div x-show="searchMode" class="min-h-0 flex-1 overflow-y-auto scrollbar-thin">
                <p x-show="searching" class="p-4 text-center text-sm text-slate-500"><span class="spinner"></span></p>
                <p x-show="!searching && q.length >= 2 && !searchResults.length" class="p-6 text-center text-sm text-slate-500">No messages found.</p>
                <template x-for="r in searchResults" :key="r.message_id">
                    <button type="button" @click="openById(r.conversation_id)" class="block w-full border-b border-slate-100 px-4 py-3 text-left hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800/60">
                        <div class="flex justify-between gap-2 text-xs text-slate-500">
                            <span class="truncate font-medium text-slate-800 dark:text-slate-200" x-text="r.contact"></span>
                            <span x-text="listTime(r.timestamp)"></span>
                        </div>
                        <p class="mt-1 line-clamp-2 text-sm text-slate-600 dark:text-slate-400" x-text="(r.direction === 'outbound' ? 'You: ' : '') + r.snippet"></p>
                    </button>
                </template>
            </div>

            {{-- Conversation list --}}
            <div x-show="!searchMode" class="min-h-0 flex-1 overflow-y-auto scrollbar-thin"
                 @scroll.debounce.100ms="if ($el.scrollTop + $el.clientHeight > $el.scrollHeight - 200) loadMoreConversations()">
                <template x-for="c in conversations" :key="c.id">
                    <button type="button" @click="open(c)"
                            class="flex w-full items-center gap-3 border-b border-slate-100 px-3 py-3 text-left transition dark:border-slate-800"
                            :class="active && active.id === c.id ? 'bg-emerald-50 dark:bg-emerald-500/10' : 'hover:bg-slate-50 dark:hover:bg-slate-800/60'">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-slate-200 text-sm font-semibold text-slate-600 dark:bg-slate-700 dark:text-slate-200" x-text="c.contact.initials"></span>
                        <span class="min-w-0 flex-1">
                            <span class="flex items-baseline justify-between gap-2">
                                <span class="truncate font-medium text-slate-900 dark:text-white" x-text="c.contact.name"></span>
                                <span class="shrink-0 text-[11px]" :class="c.unread_count ? 'font-semibold text-emerald-600 dark:text-emerald-400' : 'text-slate-400'" x-text="listTime(c.last_message_at)"></span>
                            </span>
                            <span class="mt-0.5 flex items-center justify-between gap-2">
                                <span class="truncate text-sm text-slate-500 dark:text-slate-400">
                                    <span x-show="c.last_message_direction === 'outbound'" class="text-slate-400">You: </span><span x-text="c.last_message_preview || '—'"></span>
                                </span>
                                <span x-show="c.unread_count > 0" class="min-w-[20px] shrink-0 rounded-full bg-emerald-500 px-1.5 py-0.5 text-center text-[11px] font-bold text-white" x-text="c.unread_count"></span>
                                <span x-show="!c.unread_count && c.status === 'closed'" class="shrink-0 text-[10px] uppercase text-slate-400">closed</span>
                            </span>
                        </span>
                    </button>
                </template>

                <div x-show="loadingConversations" class="p-4 text-center text-slate-400"><span class="spinner"></span></div>

                <div x-show="!loadingConversations && !conversations.length" class="px-6 py-14 text-center">
                    <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400 dark:bg-slate-800"><x-icon name="chat" class="h-7 w-7" /></span>
                    <p class="mt-3 text-sm font-semibold text-slate-800 dark:text-slate-200">No conversations</p>
                    <p class="mt-1 text-xs text-slate-500">Messages sent to this number appear here automatically.</p>
                </div>
            </div>
        </section>

        {{-- ================================================================ RIGHT: chat window --}}
        <section :class="mobileView === 'chat' ? 'flex' : 'hidden md:flex'" class="min-w-0 flex-1 flex-col">

            {{-- Nothing selected --}}
            <div x-show="!active" class="chat-wallpaper flex flex-1 flex-col items-center justify-center p-8 text-center">
                <x-application-logo class="h-16 w-16 opacity-90" />
                <h2 class="mt-4 text-lg font-semibold text-slate-800 dark:text-slate-100">KN Softic WhatsApp Inbox</h2>
                <p class="mt-1 max-w-sm text-sm text-slate-500 dark:text-slate-400">Select a conversation to start chatting. New messages appear instantly.</p>
            </div>

            <template x-if="active">
                <div class="flex min-h-0 flex-1 flex-col">
                    {{-- Header --}}
                    <header class="flex items-center gap-3 border-b border-slate-200 bg-white px-3 py-2.5 dark:border-slate-800 dark:bg-slate-900">
                        <button type="button" class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 md:hidden" @click="mobileView = 'list'" aria-label="Back">
                            <x-icon name="arrow-left" />
                        </button>
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-slate-200 text-sm font-semibold text-slate-600 dark:bg-slate-700 dark:text-slate-200" x-text="active.contact?.initials"></span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-semibold text-slate-900 dark:text-white" x-text="active.contact?.name"></p>
                            <p class="truncate text-xs text-slate-500">
                                <span x-text="active.contact?.phone"></span>
                                <span x-show="active.contact?.profile_name && active.contact?.profile_name !== active.contact?.name" x-text="' · ~' + active.contact?.profile_name"></span>
                                <span x-show="active.contact?.opt_in_status === 'opted_out'" class="text-rose-500"> · opted out</span>
                            </p>
                        </div>
                        <div x-data="{ menu: false }" class="relative">
                            <button type="button" @click="menu = !menu" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" aria-label="Conversation menu"><x-icon name="dots" /></button>
                            <div x-show="menu" x-cloak @click.outside="menu = false" @click="menu = false" class="absolute right-0 z-30 mt-1 w-52 overflow-hidden rounded-xl border border-slate-200 bg-white py-1 text-sm shadow-lg dark:border-slate-700 dark:bg-slate-800">
                                <template x-if="routes.contact && can.contacts">
                                    <a :href="url('contact', { id: active.contact.id })" class="block px-4 py-2 hover:bg-slate-50 dark:hover:bg-slate-700">Contact details</a>
                                </template>
                                <button type="button" class="block w-full px-4 py-2 text-left hover:bg-slate-50 dark:hover:bg-slate-700" @click="markUnread()">Mark as unread</button>
                                <button type="button" class="block w-full px-4 py-2 text-left hover:bg-slate-50 dark:hover:bg-slate-700" x-show="active.status !== 'closed'" @click="setStatus('closed')">Close conversation</button>
                                <button type="button" class="block w-full px-4 py-2 text-left hover:bg-slate-50 dark:hover:bg-slate-700" x-show="active.status === 'closed'" @click="setStatus('open')">Reopen conversation</button>
                            </div>
                        </div>
                    </header>

                    {{-- 24h window banner --}}
                    <div class="flex items-center gap-2 px-4 py-1.5 text-xs"
                         :class="active.within_window ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300'">
                        <x-icon name="clock" class="h-4 w-4 shrink-0" />
                        <span x-text="windowLabel()"></span>
                    </div>

                    {{-- Messages --}}
                    <div x-ref="scroller" class="chat-wallpaper min-h-0 flex-1 overflow-y-auto px-3 py-4 scrollbar-thin sm:px-8"
                         @scroll.debounce.150ms="if ($el.scrollTop < 120) loadOlder()">
                        <div x-show="loadingMessages || loadingOlder" class="py-3 text-center text-slate-500"><span class="spinner"></span></div>
                        <p x-show="!hasMoreMessages && messages.length && !loadingMessages" class="mb-3 text-center text-[11px] text-slate-500">
                            <span class="rounded-lg bg-white/80 px-2 py-1 shadow-sm dark:bg-slate-800/80">Beginning of conversation</span>
                        </p>

                        <template x-for="(m, i) in messages" :key="m.id">
                            <div>
                                <div x-show="showDay(i)" class="my-3 text-center">
                                    <span class="rounded-lg bg-white/90 px-3 py-1 text-[11px] font-medium text-slate-600 shadow-sm dark:bg-slate-800/90 dark:text-slate-300" x-text="dayLabel(m.timestamp)"></span>
                                </div>

                                <div class="group mb-1.5 flex" :class="m.direction === 'outbound' ? 'justify-end' : 'justify-start'">
                                    <button type="button" x-show="m.direction === 'outbound' && can.send && m.wa_message_id" @click="replyTo = m; $refs.composer?.focus()"
                                            class="mr-1 self-center rounded-full p-1 text-slate-400 opacity-0 transition hover:bg-black/5 group-hover:opacity-100" title="Reply"><x-icon name="reply" class="h-4 w-4" /></button>

                                    <div class="relative max-w-[85%] rounded-xl px-2.5 pb-1.5 pt-1.5 text-[14.5px] shadow-sm sm:max-w-[65%]"
                                         :class="m.direction === 'outbound'
                                            ? 'rounded-tr-sm bg-[#d9fdd3] text-slate-900 dark:bg-[#005c4b] dark:text-slate-100'
                                            : 'rounded-tl-sm bg-white text-slate-900 dark:bg-[#202c33] dark:text-slate-100'">

                                        <p x-show="m.direction === 'outbound' && m.sent_by" class="mb-0.5 text-[11px] font-semibold text-emerald-700 dark:text-emerald-300" x-text="m.sent_by"></p>

                                        {{-- Quoted reply --}}
                                        <template x-if="m.reply_to">
                                            <div class="mb-1 rounded-lg border-l-4 border-emerald-500 bg-black/5 px-2 py-1 text-xs dark:bg-white/10">
                                                <p class="font-semibold text-emerald-700 dark:text-emerald-300" x-text="m.reply_to.direction === 'outbound' ? 'You' : active.contact?.name"></p>
                                                <p class="line-clamp-2 text-slate-600 dark:text-slate-300" x-text="m.reply_to.preview"></p>
                                            </div>
                                        </template>

                                        {{-- Media --}}
                                        <template x-for="a in (m.attachments || [])" :key="a.id">
                                            <div class="mb-1">
                                                <template x-if="a.url && (a.type === 'image' || a.type === 'sticker')">
                                                    <a :href="a.url" target="_blank" rel="noopener"><img :src="a.url" :alt="a.file_name || 'image'" loading="lazy" class="max-h-80 rounded-lg object-cover" :class="a.type === 'sticker' ? 'h-32 w-32 object-contain' : 'w-full'"></a>
                                                </template>
                                                <template x-if="a.url && a.type === 'video'">
                                                    <video :src="a.url" controls preload="metadata" class="max-h-80 w-full rounded-lg bg-black"></video>
                                                </template>
                                                <template x-if="a.url && a.type === 'audio'">
                                                    <div class="flex items-center gap-2">
                                                        <span x-show="m.content?.voice" class="text-lg" title="Voice message">🎤</span>
                                                        <audio :src="a.url" controls preload="none" class="h-10 max-w-[260px]"></audio>
                                                    </div>
                                                </template>
                                                <template x-if="a.type === 'document' || !a.url">
                                                    <div class="flex items-center gap-3 rounded-lg bg-black/5 p-2.5 dark:bg-white/10">
                                                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white text-slate-500 dark:bg-slate-700"><x-icon name="template" /></span>
                                                        <div class="min-w-0 flex-1">
                                                            <p class="truncate text-sm font-medium" x-text="a.file_name || (a.type + ' file')"></p>
                                                            <p class="text-[11px] text-slate-500 dark:text-slate-400">
                                                                <span x-text="a.size"></span>
                                                                <span x-show="a.status === 'pending'"> · downloading from WhatsApp…</span>
                                                                <span x-show="a.status === 'failed'" class="text-rose-600" x-text="' · ' + (a.error || 'download failed')"></span>
                                                            </p>
                                                        </div>
                                                        <a x-show="a.download_url" :href="a.download_url" class="rounded-full p-2 text-slate-600 hover:bg-black/10 dark:text-slate-300" title="Download"><x-icon name="download" class="h-5 w-5" /></a>
                                                    </div>
                                                </template>
                                                <a x-show="a.url && a.type !== 'document'" :href="a.download_url" class="mt-0.5 inline-flex items-center gap-1 text-[11px] text-slate-500 hover:underline"><x-icon name="download" class="h-3 w-3" /> Download</a>
                                            </div>
                                        </template>

                                        {{-- Location --}}
                                        <template x-if="m.type === 'location' && m.content">
                                            <a :href="'https://www.google.com/maps?q=' + m.content.latitude + ',' + m.content.longitude" target="_blank" rel="noopener noreferrer"
                                               class="mb-1 flex items-center gap-2 rounded-lg bg-black/5 p-2.5 hover:bg-black/10 dark:bg-white/10">
                                                <x-icon name="map-pin" class="h-6 w-6 text-rose-500" />
                                                <span class="min-w-0">
                                                    <span class="block truncate text-sm font-medium" x-text="m.content.name || 'Shared location'"></span>
                                                    <span class="block truncate text-xs text-slate-500" x-text="m.content.address || (m.content.latitude + ', ' + m.content.longitude)"></span>
                                                </span>
                                            </a>
                                        </template>

                                        {{-- Contact cards --}}
                                        <template x-if="m.type === 'contacts' && m.content">
                                            <div class="mb-1 space-y-1">
                                                <template x-for="card in m.content.contacts">
                                                    <div class="flex items-center gap-2 rounded-lg bg-black/5 p-2.5 dark:bg-white/10">
                                                        <x-icon name="user" class="h-6 w-6 text-slate-500" />
                                                        <span><span class="block text-sm font-medium" x-text="card.name"></span><span class="block text-xs text-slate-500" x-text="(card.phones || []).join(', ')"></span></span>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>

                                        <p x-show="m.type === 'template'" class="mb-0.5 flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400"><x-icon name="template" class="h-3 w-3" /> Template<span x-text="m.content?.name ? ': ' + m.content.name : ''"></span></p>
                                        <p x-show="['interactive','button'].includes(m.type)" class="mb-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-500">Button reply</p>
                                        <p x-show="m.type === 'unsupported'" class="italic text-slate-500">⚠️ This message type is not supported by WhatsApp Cloud API.</p>

                                        {{-- Text / caption --}}
                                        <div x-show="m.body && m.type !== 'location' && m.type !== 'unsupported'" class="whitespace-pre-wrap break-words" x-html="formatText(m.body)"></div>

                                        {{-- Meta line --}}
                                        <div class="-mb-0.5 mt-0.5 flex items-center justify-end gap-1 text-[11px] text-slate-500 dark:text-slate-400">
                                            <span x-text="time(m.timestamp)"></span>
                                            <template x-if="m.direction === 'outbound'">
                                                <span class="inline-flex" :title="m.status">
                                                    <svg x-show="m.status === 'pending'" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                                                    <svg x-show="m.status === 'sent'" class="h-4 w-4" viewBox="0 0 16 11" fill="currentColor"><path d="M11.07.65 4.6 7.12 1.93 4.45.52 5.86l4.08 4.08L12.48 2.06z"/></svg>
                                                    <svg x-show="m.status === 'delivered' || m.status === 'read'" class="h-4 w-[18px]" :class="m.status === 'read' ? 'text-sky-500' : ''" viewBox="0 0 18 11" fill="currentColor"><path d="M12.07.65 5.6 7.12 2.93 4.45 1.52 5.86l4.08 4.08L13.48 2.06zM16.07.65 9.6 7.12l-.7-.7-1.41 1.41 2.11 2.11L17.48 2.06z"/></svg>
                                                    <svg x-show="m.status === 'failed'" class="h-4 w-4 text-rose-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg>
                                                </span>
                                            </template>
                                        </div>

                                        {{-- Reactions --}}
                                        <div x-show="(m.reactions || []).length" class="absolute -bottom-3 left-2 rounded-full bg-white px-1.5 text-sm shadow dark:bg-slate-700" x-text="(m.reactions || []).join(' ')"></div>

                                        {{-- Failure --}}
                                        <template x-if="m.status === 'failed'">
                                            <div class="mt-1 rounded-lg bg-rose-50 px-2 py-1 text-xs text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">
                                                <span x-text="m.error_message || 'Failed to send'"></span>
                                                <button type="button" x-show="can.send" @click="retry(m)" class="ml-1 font-semibold underline">Retry</button>
                                            </div>
                                        </template>
                                        <p x-show="m.status === 'pending' && m.error_message" class="mt-1 text-[11px] text-amber-700 dark:text-amber-300" x-text="m.error_message"></p>
                                    </div>

                                    <button type="button" x-show="m.direction === 'inbound' && can.send && m.wa_message_id" @click="replyTo = m; $refs.composer?.focus()"
                                            class="ml-1 self-center rounded-full p-1 text-slate-400 opacity-0 transition hover:bg-black/5 group-hover:opacity-100" title="Reply"><x-icon name="reply" class="h-4 w-4" /></button>
                                </div>
                            </div>
                        </template>
                    </div>

                    {{-- Composer --}}
                    <footer class="border-t border-slate-200 bg-slate-50 px-3 py-2.5 dark:border-slate-800 dark:bg-slate-900">
                        <template x-if="!can.send">
                            <p class="py-2 text-center text-sm text-slate-500">You have read-only access to this inbox.</p>
                        </template>

                        <template x-if="can.send && !active.within_window">
                            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
                                <span>More than 24 hours since the customer's last message. WhatsApp requires an approved template.</span>
                                <button type="button" x-show="can.template" @click="openTemplateModal()" class="rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-700">Send template</button>
                            </div>
                        </template>

                        <template x-if="can.send && active.within_window">
                            <div>
                                {{-- Reply preview --}}
                                <div x-show="replyTo" class="mb-2 flex items-start gap-2 rounded-lg border-l-4 border-emerald-500 bg-white px-3 py-2 text-xs dark:bg-slate-800">
                                    <div class="min-w-0 flex-1">
                                        <p class="font-semibold text-emerald-700 dark:text-emerald-300" x-text="replyTo?.direction === 'outbound' ? 'Replying to yourself' : 'Replying to ' + (active.contact?.name || '')"></p>
                                        <p class="truncate text-slate-500" x-text="replyTo?.body || replyTo?.type"></p>
                                    </div>
                                    <button type="button" @click="replyTo = null" class="text-slate-400 hover:text-slate-600" aria-label="Cancel reply"><x-icon name="x" class="h-4 w-4" /></button>
                                </div>
                                {{-- File preview --}}
                                <div x-show="file" class="mb-2 flex items-center gap-3 rounded-lg bg-white px-3 py-2 text-sm dark:bg-slate-800">
                                    <x-icon name="paperclip" class="h-4 w-4 text-slate-400" />
                                    <span class="min-w-0 flex-1 truncate" x-text="file?.name"></span>
                                    <label class="flex items-center gap-1 text-xs text-slate-500"><input type="checkbox" x-model="fileAsDocument" class="rounded border-slate-300 text-emerald-600"> Send as document</label>
                                    <button type="button" @click="file = null" class="text-slate-400 hover:text-slate-600" aria-label="Remove file"><x-icon name="x" class="h-4 w-4" /></button>
                                </div>

                                <div class="flex items-end gap-2">
                                    <label class="cursor-pointer rounded-full p-2.5 text-slate-500 hover:bg-slate-200 dark:hover:bg-slate-800" title="Attach file">
                                        <x-icon name="paperclip" />
                                        <input type="file" class="hidden" @change="pickFile($event)" accept=".jpg,.jpeg,.png,.mp4,.3gp,.aac,.amr,.mp3,.m4a,.ogg,.opus,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt">
                                    </label>
                                    <button type="button" x-show="can.template" @click="openTemplateModal()" class="rounded-full p-2.5 text-slate-500 hover:bg-slate-200 dark:hover:bg-slate-800" title="Send template"><x-icon name="template" /></button>
                                    <textarea x-ref="composer" x-model="draft" rows="1" maxlength="4096" @keydown="onComposerKey($event)" @input="autoGrow($el)"
                                              :placeholder="file ? 'Add a caption…' : 'Type a message'"
                                              class="form-input max-h-40 min-h-[42px] flex-1 resize-none rounded-2xl py-2.5"></textarea>
                                    <button type="button" @click="send()" :disabled="sending || (!draft.trim() && !file)"
                                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-emerald-600 text-white shadow hover:bg-emerald-700 disabled:opacity-50" aria-label="Send">
                                        <span x-show="sending" class="spinner"></span>
                                        <x-icon name="send" class="h-5 w-5" x-show="!sending" />
                                    </button>
                                </div>
                            </div>
                        </template>
                    </footer>
                </div>
            </template>
        </section>

        {{-- ================================================================ Template / new chat modal --}}
        <div x-show="templateModal || newChatModal" x-cloak class="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/60 p-0 sm:items-center sm:p-4"
             @keydown.escape.window="templateModal = false; newChatModal = false">
            <div class="flex max-h-[92vh] w-full max-w-2xl flex-col overflow-hidden rounded-t-2xl bg-white shadow-xl dark:bg-slate-900 sm:rounded-2xl" @click.outside="templateModal = false; newChatModal = false">
                <header class="flex items-center justify-between border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                    <h3 class="font-semibold text-slate-900 dark:text-white" x-text="newChatModal ? 'New conversation' : 'Send template message'"></h3>
                    <button type="button" @click="templateModal = false; newChatModal = false" class="text-slate-400 hover:text-slate-600" aria-label="Close"><x-icon name="x" /></button>
                </header>

                <div class="min-h-0 flex-1 space-y-4 overflow-y-auto p-5">
                    <p x-show="newChatModal" class="rounded-xl bg-sky-50 px-3 py-2 text-xs text-sky-800 dark:bg-sky-500/10 dark:text-sky-300">
                        WhatsApp only lets a business start a conversation with an approved template. Make sure the customer has opted in to receive messages from you.
                    </p>

                    <template x-if="newChatModal">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="form-label">Phone number (with country code)</label>
                                <input x-model="newChat.phone" class="form-input" placeholder="+91 98765 43210" inputmode="tel">
                                <p class="form-error" x-show="tplErrors.phone" x-text="(tplErrors.phone || [])[0]"></p>
                            </div>
                            <div>
                                <label class="form-label">Name (optional)</label>
                                <input x-model="newChat.name" class="form-input" maxlength="120">
                            </div>
                        </div>
                    </template>

                    <div>
                        <label class="form-label">Approved template</label>
                        <select class="form-input" @change="selectTemplate($event.target.value)">
                            <option value="">— Choose a template —</option>
                            <template x-for="t in templates" :key="t.id">
                                <option :value="t.id" x-text="t.name + ' (' + t.language + ') · ' + (t.category || '')"></option>
                            </template>
                        </select>
                        <p x-show="templatesLoaded && !templates.length" class="form-help">No approved templates for this number. Sync templates on the Message Templates page.</p>
                    </div>

                    <template x-if="tpl">
                        <div class="grid gap-5 md:grid-cols-2">
                            <div class="space-y-3">
                                <template x-if="['IMAGE','VIDEO','DOCUMENT'].includes(tpl.header_format)">
                                    <div>
                                        <label class="form-label" x-text="tpl.header_format.toLowerCase() + ' header — public HTTPS link'"></label>
                                        <input x-model="tplVars.header.link" class="form-input" placeholder="https://…">
                                        <p class="form-error" x-show="tplErrors['variables.header.link'] || tplErrors['header.link']" x-text="(tplErrors['variables.header.link'] || tplErrors['header.link'] || [])[0]"></p>
                                    </div>
                                </template>
                                <template x-for="k in tpl.header_placeholders" :key="'h' + k">
                                    <div>
                                        <label class="form-label" x-text="'Header @{{' + k + '}}'"></label>
                                        <input x-model="tplVars.header[k]" class="form-input">
                                        <p class="form-error" x-show="tplErrors['header.' + k]" x-text="(tplErrors['header.' + k] || [])[0]"></p>
                                    </div>
                                </template>
                                <template x-for="k in tpl.body_placeholders" :key="'b' + k">
                                    <div>
                                        <label class="form-label" x-text="'Body @{{' + k + '}}'"></label>
                                        <input x-model="tplVars.body[k]" class="form-input">
                                        <p class="form-error" x-show="tplErrors['body.' + k]" x-text="(tplErrors['body.' + k] || [])[0]"></p>
                                    </div>
                                </template>
                                <template x-for="b in tpl.dynamic_buttons" :key="'u' + b.index">
                                    <div>
                                        <label class="form-label" x-text="'Button “' + b.text + '” URL variable'"></label>
                                        <input x-model="tplVars.buttons[b.index]" class="form-input" :placeholder="b.url">
                                        <p class="form-error" x-show="tplErrors['buttons.' + b.index]" x-text="(tplErrors['buttons.' + b.index] || [])[0]"></p>
                                    </div>
                                </template>
                                <p x-show="!tpl.header_placeholders.length && !tpl.body_placeholders.length && !tpl.dynamic_buttons.length && !['IMAGE','VIDEO','DOCUMENT'].includes(tpl.header_format)" class="text-sm text-slate-500">This template has no variables.</p>
                            </div>

                            {{-- Preview --}}
                            <div class="chat-wallpaper rounded-xl p-4">
                                <div class="max-w-[95%] rounded-xl rounded-tr-sm bg-[#d9fdd3] p-3 text-sm text-slate-900 shadow-sm dark:bg-[#005c4b] dark:text-slate-100">
                                    <p x-show="tpl.header_format && tpl.header_format !== 'TEXT'" class="mb-2 rounded-lg bg-black/10 py-6 text-center text-xs font-semibold uppercase" x-text="tpl.header_format"></p>
                                    <p x-show="tpl.header_text" class="mb-1 font-semibold" x-text="renderTpl(tpl.header_text, tplVars.header)"></p>
                                    <p class="whitespace-pre-wrap" x-text="renderTpl(tpl.body_text, tplVars.body)"></p>
                                    <p x-show="tpl.footer_text" class="mt-1 text-xs text-slate-500 dark:text-slate-300" x-text="tpl.footer_text"></p>
                                </div>
                                <div class="mt-1 space-y-1">
                                    <template x-for="b in tpl.buttons">
                                        <div class="max-w-[95%] rounded-lg bg-white py-1.5 text-center text-sm font-medium text-sky-600 shadow-sm dark:bg-[#202c33] dark:text-sky-400" x-text="b.text"></div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                <footer class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3 dark:border-slate-800">
                    <x-button type="button" variant="secondary" x-on:click="templateModal = false; newChatModal = false">Cancel</x-button>
                    <x-button type="button" ::disabled="!tpl || tplSending" x-on:click="newChatModal ? startChat() : sendTemplate()">
                        <span x-show="tplSending" class="spinner"></span> Send template
                    </x-button>
                </footer>
            </div>
        </div>
    </div>
    @endif
</x-app-layout>
