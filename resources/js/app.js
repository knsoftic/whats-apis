import './bootstrap';

import Alpine from 'alpinejs';
import chatApp from './chat';

window.Alpine = Alpine;

Alpine.data('chatApp', chatApp);

/**
 * JSON fetch helper with CSRF + same-origin cookies.
 * Throws an Error with `.status` and `.data` on non-2xx responses.
 */
window.http = async function (url, { method = 'GET', body = null, headers = {} } = {}) {
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    const isForm = body instanceof FormData;
    const res = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(token ? { 'X-CSRF-TOKEN': token } : {}),
            ...(body && !isForm ? { 'Content-Type': 'application/json' } : {}),
            ...headers,
        },
        body: body ? (isForm ? body : JSON.stringify(body)) : null,
    });

    let data = null;
    try {
        data = await res.json();
    } catch (e) {
        data = null;
    }

    if (!res.ok) {
        const firstError = data?.errors ? Object.values(data.errors)[0]?.[0] : null;
        const err = new Error(firstError || data?.message || `Request failed (${res.status})`);
        err.status = res.status;
        err.data = data;
        throw err;
    }

    return data;
};

window.toast = (message, type = 'success') =>
    window.dispatchEvent(new CustomEvent('toast', { detail: { message, type } }));

Alpine.data('toasts', (initial = []) => ({
    items: [],
    nextId: 1,
    init() {
        initial.forEach((t) => this.add(t));
    },
    add({ message, type = 'success', timeout = 5000 }) {
        if (!message) return;
        const id = this.nextId++;
        this.items.push({ id, message, type, visible: true });
        if (timeout) setTimeout(() => this.dismiss(id), timeout);
    },
    dismiss(id) {
        const t = this.items.find((i) => i.id === id);
        if (t) t.visible = false;
        setTimeout(() => (this.items = this.items.filter((i) => i.id !== id)), 200);
    },
}));

Alpine.data('themeToggle', () => ({
    dark: document.documentElement.classList.contains('dark'),
    toggle() {
        this.dark = !this.dark;
        document.documentElement.classList.toggle('dark', this.dark);
        try {
            localStorage.setItem('theme', this.dark ? 'dark' : 'light');
        } catch (e) {}
    },
}));

Alpine.data('copyText', (text) => ({
    copied: false,
    async copy() {
        try {
            await navigator.clipboard.writeText(text);
        } catch (e) {
            const ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            ta.remove();
        }
        this.copied = true;
        setTimeout(() => (this.copied = false), 1500);
    },
}));

// Inline page scripts can register components with:
// document.addEventListener('alpine:init', () => Alpine.data('name', () => ({...})))
Alpine.start();
