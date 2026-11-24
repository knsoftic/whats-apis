/**
 * WhatsApp Web-style inbox (Alpine component "chatApp").
 * Data: JSON endpoints under /chat. Real-time: Laravel Echo (Reverb) on the
 * private channel "whatsapp.account.{id}". If the socket is not connected,
 * the component falls back to polling every 10 seconds.
 */

const escapeHtml = (s) =>
    String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

/** Escape first, then turn http(s) URLs and *bold* / _italic_ into markup. */
export function formatText(text) {
    let html = escapeHtml(text);
    html = html.replace(/(https?:\/\/[^\s<]+)/g, (url) => `<a href="${url}" target="_blank" rel="noopener noreferrer nofollow" class="underline break-all">${url}</a>`);
    const end = '(?=[\\s.,!?;:)]|$)';
    html = html.replace(new RegExp('(^|\\s)\\*([^*\\n]+)\\*' + end, 'g'), '$1<strong>$2</strong>');
    html = html.replace(new RegExp('(^|\\s)_([^_\\n]+)_' + end, 'g'), '$1<em>$2</em>');
    html = html.replace(new RegExp('(^|\\s)~([^~\\n]+)~' + end, 'g'), '$1<del>$2</del>');
    return html;
}

/** Browser recorder objects live outside Alpine's reactive state (proxies break native methods). */
const voice = { recorder: null, stream: null, chunks: [], mime: '' };

export default function chatApp(config) {
    return {
        // ---- config / permissions
        accounts: config.accounts,
        accountId: config.accountId,
        can: config.can,
        routes: config.routes,

        // ---- conversation list
        conversations: [],
        page: 1,
        hasMoreConversations: false,
        loadingConversations: false,
        q: '',
        filter: 'all',
        unreadTotal: 0,

        // ---- message search
        searchMode: false,
        searchResults: [],
        searching: false,

        // ---- active conversation
        active: null,
        messages: [],
        hasMoreMessages: false,
        loadingMessages: false,
        loadingOlder: false,

        // ---- composer
        draft: '',
        replyTo: null,
        file: null,
        fileAsDocument: false,
        sending: false,
        // voice notes
        recording: false,
        recSeconds: 0,
        recTimer: null,

        // ---- templates / new chat
        templates: [],
        templatesLoaded: false,
        templateModal: false,
        newChatModal: false,
        tpl: null,
        tplVars: { header: {}, body: {}, buttons: {} },
        tplErrors: {},
        tplSending: false,
        newChat: { phone: '', name: '' },

        // ---- realtime
        channel: null,
        connected: false,
        pollTimer: null,
        mobileView: 'list',
        now: Date.now(),

        init() {
            if (!this.accountId) return;
            this.loadConversations();
            if (config.conversationId) this.openById(config.conversationId);
            this.subscribe();
            this.pollTimer = setInterval(() => this.poll(), 10000);
            setInterval(() => (this.now = Date.now()), 60000);
            this.$watch('q', () => this.debounce('search', () => this.searchMode ? this.searchMessages() : this.reloadConversations(), 300));
            this.$watch('filter', () => this.reloadConversations());
        },

        destroy() {
            clearInterval(this.pollTimer);
            this.unsubscribe();
        },

        // ================================================================ helpers
        _timers: {},
        debounce(key, fn, ms) {
            clearTimeout(this._timers[key]);
            this._timers[key] = setTimeout(fn, ms);
        },
        url(name, params = {}) {
            let u = this.routes[name];
            Object.entries(params).forEach(([k, v]) => (u = u.replace(`__${k}__`, v)));
            return u;
        },
        get account() {
            return this.accounts.find((a) => a.id === this.accountId) || null;
        },
        formatText,
        time(iso) {
            return iso ? new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
        },
        listTime(iso) {
            if (!iso) return '';
            const d = new Date(iso);
            const today = new Date();
            if (d.toDateString() === today.toDateString()) return this.time(iso);
            const y = new Date(today);
            y.setDate(today.getDate() - 1);
            if (d.toDateString() === y.toDateString()) return 'Yesterday';
            return d.toLocaleDateString([], { day: '2-digit', month: 'short' });
        },
        dayLabel(iso) {
            const d = new Date(iso);
            const today = new Date();
            const y = new Date(today);
            y.setDate(today.getDate() - 1);
            if (d.toDateString() === today.toDateString()) return 'Today';
            if (d.toDateString() === y.toDateString()) return 'Yesterday';
            return d.toLocaleDateString([], { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
        },
        showDay(index) {
            if (index === 0) return true;
            const a = new Date(this.messages[index - 1].timestamp).toDateString();
            const b = new Date(this.messages[index].timestamp).toDateString();
            return a !== b;
        },
        windowLabel() {
            if (!this.active) return '';
            if (!this.active.within_window) return 'Customer service window closed — only approved templates can be sent';
            const ms = new Date(this.active.window_expires_at).getTime() - this.now;
            const h = Math.floor(ms / 3600000);
            const m = Math.floor((ms % 3600000) / 60000);
            return `Reply window open · ${h > 0 ? h + 'h ' : ''}${m}m left`;
        },
        isNearBottom() {
            const el = this.$refs.scroller;
            return !el || el.scrollHeight - el.scrollTop - el.clientHeight < 150;
        },
        scrollToBottom(force = false) {
            this.$nextTick(() => {
                const el = this.$refs.scroller;
                if (el && (force || this.isNearBottom())) el.scrollTop = el.scrollHeight;
            });
        },
        notify(e) {
            window.toast(e?.message || 'Something went wrong', 'error');
        },

        // ========================================================== conversations
        async loadConversations(append = false) {
            if (!this.accountId) return;
            this.loadingConversations = true;
            try {
                const params = new URLSearchParams({ account_id: this.accountId, filter: this.filter, page: this.page });
                if (this.q && !this.searchMode) params.set('q', this.q);
                const res = await http(`${this.routes.conversations}?${params}`);
                this.conversations = append ? [...this.conversations, ...res.data.filter((c) => !this.conversations.some((x) => x.id === c.id))] : res.data;
                this.hasMoreConversations = res.has_more;
                this.unreadTotal = res.unread_total;
            } catch (e) {
                this.notify(e);
            } finally {
                this.loadingConversations = false;
            }
        },
        reloadConversations() {
            this.page = 1;
            this.loadConversations();
        },
        loadMoreConversations() {
            if (this.loadingConversations || !this.hasMoreConversations) return;
            this.page++;
            this.loadConversations(true);
        },
        upsertConversation(conv, moveTop = true) {
            if (!conv || conv.account_id !== this.accountId) return;
            const i = this.conversations.findIndex((c) => c.id === conv.id);
            if (i >= 0) this.conversations.splice(i, 1);
            if (this.active && this.active.id === conv.id) {
                this.active = { ...this.active, ...conv };
            }
            if (i >= 0 || moveTop) this.conversations.unshift(conv);
        },
        switchAccount(id) {
            if (id === this.accountId) return;
            this.unsubscribe();
            this.accountId = id;
            this.active = null;
            this.messages = [];
            this.templatesLoaded = false;
            this.mobileView = 'list';
            history.replaceState(null, '', `${this.routes.index}?account=${id}`);
            this.reloadConversations();
            this.subscribe();
        },

        // ============================================================== messages
        async openById(id) {
            await this.open({ id });
        },
        async open(conv) {
            this.active = conv.contact ? { ...conv } : null;
            this.messages = [];
            this.replyTo = null;
            this.file = null;
            this.mobileView = 'chat';
            this.loadingMessages = true;
            try {
                const res = await http(this.url('show', { id: conv.id }));
                this.active = res.conversation;
                this.messages = res.messages;
                this.hasMoreMessages = res.has_more;
                history.replaceState(null, '', `${this.routes.index}?conversation=${conv.id}`);
                this.scrollToBottom(true);
                this.markRead();
                this.$nextTick(() => this.$refs.composer?.focus());
            } catch (e) {
                this.notify(e);
                this.active = null;
            } finally {
                this.loadingMessages = false;
            }
        },
        async loadOlder() {
            if (!this.active || this.loadingOlder || !this.hasMoreMessages || !this.messages.length) return;
            this.loadingOlder = true;
            const el = this.$refs.scroller;
            const before = el.scrollHeight;
            try {
                const res = await http(`${this.url('show', { id: this.active.id })}?before_id=${this.messages[0].id}`);
                this.messages = [...res.messages, ...this.messages];
                this.hasMoreMessages = res.has_more;
                this.$nextTick(() => (el.scrollTop = el.scrollHeight - before));
            } catch (e) {
                this.notify(e);
            } finally {
                this.loadingOlder = false;
            }
        },
        upsertMessage(msg) {
            if (!this.active || msg.conversation_id !== this.active.id) return;
            const i = this.messages.findIndex((m) => m.id === msg.id);
            if (i >= 0) {
                this.messages[i] = { ...this.messages[i], ...msg };
            } else {
                const stick = this.isNearBottom();
                this.messages.push({ reactions: [], ...msg });
                this.messages.sort((a, b) => a.id - b.id);
                if (stick || msg.direction === 'outbound') this.scrollToBottom(true);
            }
        },
        async markRead() {
            if (!this.active) return;
            const c = this.conversations.find((x) => x.id === this.active.id);
            if (c) c.unread_count = 0;
            try {
                await http(this.url('read', { id: this.active.id }), { method: 'POST' });
            } catch (e) {
                /* non-critical */
            }
        },
        async markUnread() {
            if (!this.active) return;
            await http(this.url('unread', { id: this.active.id }), { method: 'POST' });
            const c = this.conversations.find((x) => x.id === this.active.id);
            if (c) c.unread_count = Math.max(1, c.unread_count);
            this.active = null;
            this.mobileView = 'list';
        },
        async setBot(action) {
            try {
                const res = await http(this.routes.bot.replace('__id__', this.active.id), { method: 'PATCH', body: { action } });
                this.upsertConversation(res.conversation, false);
                window.toast(action === 'pause' ? 'Bot paused in this chat' : 'Bot resumed in this chat');
            } catch (e) {
                this.notify(e);
            }
        },
        async setBlocked(block) {
            if (block && !confirm('Block this customer on WhatsApp? They will not be able to message or call this number.')) return;
            try {
                const res = await http(this.routes.block.replace('__id__', this.active.contact.id), { method: block ? 'POST' : 'DELETE' });
                this.active.contact.blocked = res.blocked;
                window.toast(res.message);
            } catch (e) {
                this.notify(e);
            }
        },
        async setStatus(status) {
            try {
                const res = await http(this.url('update', { id: this.active.id }), { method: 'PATCH', body: { status } });
                this.upsertConversation(res.conversation, false);
                window.toast(status === 'closed' ? 'Conversation closed' : 'Conversation reopened');
            } catch (e) {
                this.notify(e);
            }
        },

        // ============================================================== sending
        pickFile(event) {
            const f = event.target.files[0];
            event.target.value = '';
            if (!f) return;
            if (f.size > 100 * 1024 * 1024) return window.toast('Files can be at most 100 MB on WhatsApp.', 'error');
            this.file = f;
            this.fileAsDocument = !/^(image\/(jpeg|png)|video\/(mp4|3gpp)|audio\/)/.test(f.type);
            this.$nextTick(() => this.$refs.composer?.focus());
        },
        onComposerKey(e) {
            if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
                e.preventDefault();
                this.send();
            }
        },
        async send() {
            if (!this.active || this.sending) return;
            const text = this.draft.trim();
            if (!text && !this.file) return;
            this.sending = true;
            try {
                let res;
                if (this.file) {
                    const form = new FormData();
                    form.append('file', this.file);
                    if (text) form.append('caption', text);
                    if (this.fileAsDocument) form.append('as', 'document');
                    if (this.replyTo) form.append('reply_to_id', this.replyTo.id);
                    res = await http(this.url('media', { id: this.active.id }), { method: 'POST', body: form });
                } else {
                    res = await http(this.url('text', { id: this.active.id }), {
                        method: 'POST',
                        body: { body: text, reply_to_id: this.replyTo?.id ?? null },
                    });
                }
                this.draft = '';
                this.file = null;
                this.replyTo = null;
                this.upsertMessage(res.message);
                this.upsertConversation(res.conversation);
                this.$nextTick(() => this.autoGrow(this.$refs.composer));
            } catch (e) {
                this.notify(e);
                if (e.data?.reason === 'window_closed' && this.active) this.active.within_window = false;
            } finally {
                this.sending = false;
                this.$nextTick(() => this.$refs.composer?.focus());
            }
        },
        // ============================================================ voice notes
        canRecord() {
            return !!(window.MediaRecorder && navigator.mediaDevices?.getUserMedia);
        },
        formatDuration(s) {
            return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
        },
        async startRecording() {
            if (this.recording || !this.active) return;
            if (!window.isSecureContext) {
                window.toast('Voice notes need the site to open over https://', 'error');
                return;
            }
            try {
                voice.stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } });
            } catch (e) {
                window.toast(await this.micErrorMessage(e), 'error');
                return;
            }
            // OGG/Opus is sent as-is; WebM / MP4 are converted to OGG on the server.
            const types = ['audio/ogg;codecs=opus', 'audio/webm;codecs=opus', 'audio/webm', 'audio/mp4'];
            voice.mime = types.find((t) => MediaRecorder.isTypeSupported(t)) || '';
            voice.chunks = [];
            voice.recorder = new MediaRecorder(voice.stream, voice.mime ? { mimeType: voice.mime, audioBitsPerSecond: 32000 } : undefined);
            voice.recorder.ondataavailable = (e) => e.data.size && voice.chunks.push(e.data);
            voice.recorder.start(250);
            this.recording = true;
            this.recSeconds = 0;
            this.recTimer = setInterval(() => {
                this.recSeconds++;
                if (this.recSeconds >= 300) this.stopAndSendVoice(); // 5 minutes max
            }, 1000);
        },
        /** Explains why the microphone can't be used and how to fix it. */
        async micErrorMessage(e) {
            if (e.name === 'NotFoundError' || e.name === 'OverconstrainedError') return 'No microphone found on this device.';
            if (e.name === 'NotReadableError') return 'The microphone is being used by another app (e.g. a call). Close it and try again.';
            const policy = document.permissionsPolicy || document.featurePolicy;
            if (policy?.allowsFeature && !policy.allowsFeature('microphone')) {
                return 'The site does not allow the microphone yet — the administrator needs to run the latest update.';
            }
            let state = null;
            try {
                state = (await navigator.permissions?.query({ name: 'microphone' }))?.state;
            } catch (err) {}
            if (state === 'denied' || e.name === 'NotAllowedError') {
                return 'Microphone is blocked for this site. Click the 🔒 icon left of the address bar → Site settings (Permissions) → Microphone → Allow, then reload the page.';
            }
            return 'Could not start the microphone: ' + (e.message || e.name);
        },
        stopRecorder() {
            return new Promise((resolve) => {
                clearInterval(this.recTimer);
                if (!voice.recorder || voice.recorder.state === 'inactive') return resolve();
                voice.recorder.onstop = () => resolve();
                voice.recorder.stop();
            }).finally(() => {
                voice.stream?.getTracks().forEach((t) => t.stop());
                voice.stream = null;
                this.recording = false;
            });
        },
        async cancelRecording() {
            await this.stopRecorder();
            voice.chunks = [];
        },
        async stopAndSendVoice() {
            if (!this.recording || this.sending) return;
            const seconds = this.recSeconds;
            await this.stopRecorder();
            if (seconds < 1 || !voice.chunks.length) {
                window.toast('Hold on a bit longer — the voice note was too short.', 'error');
                return;
            }
            const type = voice.recorder?.mimeType || voice.mime || 'audio/webm';
            const ext = type.includes('ogg') ? 'ogg' : type.includes('mp4') ? 'm4a' : 'webm';
            const form = new FormData();
            form.append('file', new Blob(voice.chunks, { type }), `voice-note.${ext}`);
            form.append('voice', '1');
            if (this.replyTo) form.append('reply_to_id', this.replyTo.id);
            voice.chunks = [];
            this.sending = true;
            try {
                const res = await http(this.url('media', { id: this.active.id }), { method: 'POST', body: form });
                this.replyTo = null;
                this.upsertMessage(res.message);
                this.upsertConversation(res.conversation);
            } catch (e) {
                this.notify(e);
                if (e.data?.reason === 'window_closed' && this.active) this.active.within_window = false;
            } finally {
                this.sending = false;
            }
        },
        async retry(msg) {
            try {
                const res = await http(this.url('retry', { id: msg.id }), { method: 'POST' });
                this.upsertMessage(res.message);
            } catch (e) {
                this.notify(e);
            }
        },
        autoGrow(el) {
            if (!el) return;
            el.style.height = 'auto';
            el.style.height = Math.min(el.scrollHeight, 160) + 'px';
        },

        // ============================================================ templates
        async loadTemplates() {
            if (this.templatesLoaded) return;
            try {
                const res = await http(`${this.routes.templates}?account_id=${this.accountId}`);
                this.templates = res.data;
                this.templatesLoaded = true;
            } catch (e) {
                this.notify(e);
            }
        },
        async openTemplateModal(forNewChat = false) {
            await this.loadTemplates();
            this.tpl = null;
            this.tplVars = { header: {}, body: {}, buttons: {} };
            this.tplErrors = {};
            if (forNewChat) {
                this.newChat = { phone: '', name: '' };
                this.newChatModal = true;
            } else {
                this.templateModal = true;
            }
        },
        selectTemplate(id) {
            this.tpl = this.templates.find((t) => t.id === Number(id)) || null;
            this.tplVars = { header: {}, body: {}, buttons: {} };
            this.tplErrors = {};
        },
        renderTpl(text, values) {
            return String(text || '').replace(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g, (m, k) => (values[k] ? values[k] : m));
        },
        templatePayload() {
            const v = { header: { ...this.tplVars.header }, body: { ...this.tplVars.body }, buttons: { ...this.tplVars.buttons } };
            return { template_id: this.tpl.id, variables: v };
        },
        async sendTemplate() {
            if (!this.tpl || !this.active) return;
            this.tplSending = true;
            this.tplErrors = {};
            try {
                const res = await http(this.url('template', { id: this.active.id }), { method: 'POST', body: this.templatePayload() });
                this.templateModal = false;
                this.upsertMessage(res.message);
                this.upsertConversation(res.conversation);
                window.toast('Template message queued');
            } catch (e) {
                this.tplErrors = e.data?.errors || {};
                this.notify(e);
            } finally {
                this.tplSending = false;
            }
        },
        async startChat() {
            if (!this.tpl) return;
            this.tplSending = true;
            this.tplErrors = {};
            try {
                const res = await http(this.routes.start, {
                    method: 'POST',
                    body: { account_id: this.accountId, phone: this.newChat.phone, name: this.newChat.name || null, ...this.templatePayload() },
                });
                this.newChatModal = false;
                this.upsertConversation(res.conversation);
                await this.open(res.conversation);
                window.toast('Conversation started');
            } catch (e) {
                this.tplErrors = e.data?.errors || {};
                this.notify(e);
            } finally {
                this.tplSending = false;
            }
        },

        // ========================================================= message search
        toggleSearchMode() {
            this.searchMode = !this.searchMode;
            this.searchResults = [];
            if (this.searchMode && this.q.length >= 2) this.searchMessages();
            if (!this.searchMode) this.reloadConversations();
        },
        async searchMessages() {
            if (this.q.trim().length < 2) {
                this.searchResults = [];
                return;
            }
            this.searching = true;
            try {
                const res = await http(`${this.routes.search}?account_id=${this.accountId}&q=${encodeURIComponent(this.q.trim())}`);
                this.searchResults = res.data;
            } catch (e) {
                this.notify(e);
            } finally {
                this.searching = false;
            }
        },

        // ============================================================== realtime
        subscribe() {
            if (!window.Echo || !this.accountId) return;
            try {
                const pusher = window.Echo.connector?.pusher;
                if (pusher && !this._stateBound) {
                    this._stateBound = true;
                    this.connected = pusher.connection.state === 'connected';
                    pusher.connection.bind('state_change', (s) => {
                        const was = this.connected;
                        this.connected = s.current === 'connected';
                        if (this.connected && !was) this.poll(); // catch up after reconnect
                    });
                }
                this.channel = window.Echo.private(`whatsapp.account.${this.accountId}`)
                    .listen('.message.received', (e) => {
                        this.upsertConversation(e.conversation);
                        if (this.active && e.message.conversation_id === this.active.id) {
                            this.upsertMessage(e.message);
                            if (document.visibilityState === 'visible') this.debounce('read', () => this.markRead(), 800);
                        }
                    })
                    .listen('.message.updated', (e) => {
                        this.upsertConversation(e.conversation);
                        this.upsertMessage(e.message);
                    })
                    .listen('.message.status', (e) => {
                        const m = this.messages.find((x) => x.id === e.id);
                        if (m) Object.assign(m, { status: e.status, error_code: e.error_code, error_message: e.error_message, wa_message_id: e.wa_message_id });
                    })
                    .listen('.conversation.read', (e) => {
                        const c = this.conversations.find((x) => x.id === e.conversation_id);
                        if (c) c.unread_count = e.unread_count;
                    });
            } catch (e) {
                console.warn('Realtime unavailable, using polling', e);
            }
        },
        unsubscribe() {
            if (window.Echo && this.accountId) window.Echo.leave(`whatsapp.account.${this.accountId}`);
            this.channel = null;
        },
        async poll() {
            if (this.connected || document.visibilityState !== 'visible' || !this.accountId) return;
            if (!this.searchMode && this.page === 1) this.loadConversations();
            if (this.active && this.messages.length) {
                try {
                    const last = this.messages[this.messages.length - 1].id;
                    const res = await http(`${this.url('show', { id: this.active.id })}?after_id=${last}`);
                    res.messages.forEach((m) => this.upsertMessage(m));
                    // refresh statuses of recent outgoing messages
                    const recent = await http(this.url('show', { id: this.active.id }));
                    recent.messages.forEach((m) => {
                        const x = this.messages.find((y) => y.id === m.id);
                        if (x && (x.status !== m.status || (m.attachments || []).some((a) => a.url))) Object.assign(x, m);
                    });
                    this.active = recent.conversation;
                    if (res.messages.some((m) => m.direction === 'inbound')) this.markRead();
                } catch (e) {
                    /* ignore polling errors */
                }
            }
        },
    };
}
