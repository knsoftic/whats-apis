/**
 * Sidebar inbox counters (Alpine component "chatCounts"): unread chats badge,
 * unread messages, chats waiting for a reply, open and total chats, plus an
 * "(N)" prefix on the browser tab title.
 *
 * Refreshes every 15 s while the tab is visible, right away when the tab gets
 * focus, and instantly from the chat page ("chat:unread" event — with the
 * fresh numbers in event.detail when the chat page already has them).
 */
export default function chatCounts(initial, url) {
    return {
        c: { all: 0, unread: 0, messages: 0, waiting: 0, open: 0, closed: 0, ...(initial || {}) },
        lastUpdate: Date.now(),
        busy: false,
        _timer: null,
        _debounce: null,

        init() {
            this.applyTitle();
            this._timer = setInterval(() => {
                if (Date.now() - this.lastUpdate >= 14000) this.refresh();
            }, 15000);
            this._onEvent = (e) => {
                if (e.detail?.total) return this.set(e.detail.total);
                clearTimeout(this._debounce);
                this._debounce = setTimeout(() => this.refresh(), 400);
            };
            this._onVisible = () => document.visibilityState === 'visible' && Date.now() - this.lastUpdate > 3000 && this.refresh();
            window.addEventListener('chat:unread', this._onEvent);
            document.addEventListener('visibilitychange', this._onVisible);
            window.addEventListener('focus', this._onVisible);
        },

        destroy() {
            clearInterval(this._timer);
            window.removeEventListener('chat:unread', this._onEvent);
            document.removeEventListener('visibilitychange', this._onVisible);
            window.removeEventListener('focus', this._onVisible);
        },

        set(total) {
            this.c = { ...this.c, ...total };
            this.lastUpdate = Date.now();
            this.applyTitle();
        },

        async refresh() {
            if (this.busy || document.visibilityState !== 'visible') return;
            this.busy = true;
            try {
                const res = await window.http(url);
                if (res?.total) this.set(res.total);
                // Chat page (if open) updates its tab counts / number switcher from the same numbers.
                if (res?.accounts) window.dispatchEvent(new CustomEvent('chat:counts', { detail: res }));
            } catch (e) {
                // Signed out / offline — keep the last numbers; a 401/419 stops further polling.
                if (e.status === 401 || e.status === 419) clearInterval(this._timer);
            } finally {
                this.busy = false;
            }
        },

        fmt(n) {
            return n > 999 ? '999+' : String(n || 0);
        },

        applyTitle() {
            const base = document.title.replace(/^\(\d+\+?\)\s+/, '');
            const n = this.c.unread || 0;
            document.title = n ? `(${n > 99 ? '99+' : n}) ${base}` : base;
        },
    };
}
