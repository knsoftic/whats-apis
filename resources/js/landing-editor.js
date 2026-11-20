/**
 * Settings → Landing page editor.
 * The whole page content lives in `c`; on save it is sent as one JSON field
 * and cleaned again on the server (LandingContent::sanitize).
 */
const MEDIA_FIELDS = ['seo.og_image', 'hero.image', 'hero.video', 'video.file'];

let uid = 0;
const withIds = (list) => (Array.isArray(list) ? list.map((row) => ({ ...row, _id: ++uid })) : []);

export default (initial, cfg) => ({
    c: initial,
    media: cfg.media || [],
    open: 'hero',
    dirty: false,
    saving: false,
    uploading: null,
    library: { open: false, field: null, type: 'image' },
    iconPicker: { open: false, item: null },

    init() {
        ['stats', 'features', 'steps', 'testimonials', 'faq'].forEach((s) => {
            this.c[s].items = withIds(this.c[s].items);
        });
        this.$nextTick(() => this.$watch('c', () => (this.dirty = true)));
        window.addEventListener('beforeunload', (e) => {
            if (this.dirty && !this.saving) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
        const hash = window.location.hash.replace('#', '');
        if (hash && this.c[hash] !== undefined) this.open = hash;
    },

    toggle(section) {
        this.open = this.open === section ? null : section;
    },

    // ---------------------------------------------------------------- lists
    add(section, row) {
        this.c[section].items.push({ ...row, _id: ++uid });
    },
    remove(section, i) {
        this.c[section].items.splice(i, 1);
    },
    move(section, i, dir) {
        const list = this.c[section].items;
        const j = i + dir;
        if (j < 0 || j >= list.length) return;
        const [row] = list.splice(i, 1);
        list.splice(j, 0, row);
    },

    // ---------------------------------------------------------------- paths
    get(path) {
        return path.split('.').reduce((o, k) => (o ? o[k] : ''), this.c) || '';
    },
    set(path, value) {
        const keys = path.split('.');
        const last = keys.pop();
        keys.reduce((o, k) => o[k], this.c)[last] = value;
    },
    url(value) {
        if (!value) return '';
        return value.startsWith('uploads/') ? `${cfg.base}/${value}` : value;
    },
    isVideoUrl(value) {
        return /\.(mp4|webm)(\?|$)/i.test(value || '');
    },

    // ---------------------------------------------------------------- media
    async upload(event, path, type) {
        const file = event.target.files[0];
        if (!file) return;
        const fd = new FormData();
        fd.append('file', file);
        fd.append('type', type);
        this.uploading = path;
        try {
            const res = await window.http(cfg.uploadUrl, { method: 'POST', body: fd });
            this.media.unshift(res.file);
            this.set(path, res.file.path);
            window.toast('Uploaded — remember to save.');
        } catch (e) {
            window.toast(e.status === 413 ? `File is bigger than the server allows (${cfg.uploadLimit}).` : e.message, 'error');
        } finally {
            this.uploading = null;
            event.target.value = '';
        }
    },
    openLibrary(path, type) {
        this.library = { open: true, field: path, type };
    },
    libraryItems() {
        return this.media.filter((m) => !this.library.field || m.type === this.library.type);
    },
    choose(file) {
        if (this.library.field) this.set(this.library.field, file.path);
        this.library.open = false;
    },
    async deleteMedia(file) {
        if (!confirm(`Delete ${file.name}? If it is used on the page it will be removed there too.`)) return;
        try {
            await window.http(cfg.deleteUrl, { method: 'DELETE', body: { path: file.path } });
            this.media = this.media.filter((m) => m.path !== file.path);
            MEDIA_FIELDS.forEach((f) => {
                if (this.get(f) === file.path) this.set(f, '');
            });
            window.toast('File deleted.');
        } catch (e) {
            window.toast(e.message, 'error');
        }
    },

    // ---------------------------------------------------------------- icons
    iconSvg(name) {
        return this.$root.querySelector(`[data-icon="${name}"]`)?.innerHTML || '';
    },
    pickIcon(item) {
        this.iconPicker = { open: true, item };
    },
    setIcon(name) {
        if (this.iconPicker.item) this.iconPicker.item.icon = name;
        this.iconPicker.open = false;
    },

    // ---------------------------------------------------------------- save
    prepare() {
        this.saving = true;
        const strip = (list) => list.map(({ _id, ...row }) => row);
        const out = JSON.parse(JSON.stringify(this.c));
        ['stats', 'features', 'steps', 'testimonials', 'faq'].forEach((s) => (out[s].items = strip(out[s].items)));
        this.$refs.json.value = JSON.stringify(out);
    },
});
