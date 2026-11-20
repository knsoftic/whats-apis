/**
 * Visual bot flow builder (n8n-style): drag nodes onto a canvas, connect an
 * output dot to the next node, edit the selected node on the right.
 *
 * Saved as { nodes:[{id,type,x,y,data}], edges:[{from,port,to}] } and
 * validated again on the server (App\Support\BotGraph).
 */

// Node geometry (px) — must match the Blade markup.
const W = 260; // node width
const HEAD_CENTER = 23; // vertical centre of the header (ports)
const ROWS_TOP = 97; // first output row (1 border + 44 header + 52 body)
const ROW = 34; // output row height (menu options, question results, condition rules)
const ROW_NODES = ['menu', 'question', 'condition'];
const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const GRID = 20;

const META = {
    trigger: { label: 'Trigger', icon: 'bolt', tone: 'amber', hint: 'When the flow starts' },
    text: { label: 'Message', icon: 'chat', tone: 'sky', hint: 'Send a text message' },
    menu: { label: 'Menu', icon: 'menu', tone: 'violet', hint: 'Buttons / list — each option has its own path' },
    question: { label: 'Question', icon: 'note', tone: 'cyan', hint: 'Ask, wait for the reply, save it' },
    condition: { label: 'Condition', icon: 'filter', tone: 'orange', hint: 'If / else — split the flow by rules' },
    image: { label: 'Image', icon: 'photo', tone: 'emerald', hint: 'Send a picture with a caption' },
    document: { label: 'Document', icon: 'paperclip', tone: 'slate', hint: 'Send a PDF or other file' },
    handoff: { label: 'Handoff to agent', icon: 'user', tone: 'rose', hint: 'Pause the bot, a person takes over' },
    goto: { label: 'Go to flow', icon: 'arrow-up-right', tone: 'indigo', hint: 'Continue in another flow' },
};

const TONES = {
    amber: 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300',
    sky: 'bg-sky-50 text-sky-800 dark:bg-sky-500/10 dark:text-sky-300',
    violet: 'bg-violet-50 text-violet-800 dark:bg-violet-500/10 dark:text-violet-300',
    emerald: 'bg-emerald-50 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300',
    slate: 'bg-slate-100 text-slate-700 dark:bg-slate-700/40 dark:text-slate-200',
    rose: 'bg-rose-50 text-rose-800 dark:bg-rose-500/10 dark:text-rose-300',
    indigo: 'bg-indigo-50 text-indigo-800 dark:bg-indigo-500/10 dark:text-indigo-300',
    cyan: 'bg-cyan-50 text-cyan-800 dark:bg-cyan-500/10 dark:text-cyan-300',
    orange: 'bg-orange-50 text-orange-800 dark:bg-orange-500/10 dark:text-orange-300',
};

const newRule = () => ({ id: uid('r'), kind: 'text', subject: 'message', var: '', op: 'contains', value: '', days: [1, 2, 3, 4, 5], from: '09:00', to: '18:00' });

const uid = (prefix) => prefix + Math.random().toString(36).slice(2, 8);
const clone = (v) => JSON.parse(JSON.stringify(v));

const defaults = (type) =>
    ({
        trigger: {},
        text: { text: '' },
        menu: {
            text: '',
            footer: '',
            button_label: '',
            options: [
                { id: uid('o'), title: 'Option 1', description: '' },
                { id: uid('o'), title: 'Option 2', description: '' },
            ],
        },
        image: { url: '', caption: '' },
        document: { url: '', caption: '', filename: '' },
        question: { text: '', var: 'name', validate: 'any', retry_text: '', attempts: 2, save_to: 'none' },
        condition: { rules: [newRule()] },
        handoff: { text: 'Connecting you to our team — please wait a moment.' },
        goto: { flow_id: '' },
    })[type];

export default (cfg) => ({
    nodes: [],
    edges: [],
    s: cfg.settings,
    targets: cfg.targets || {},
    accounts: cfg.accounts || [],
    triggers: cfg.triggers,
    matches: cfg.matches,
    textOps: cfg.textOps,
    tags: cfg.tags || {},
    meta: META,
    days: DAYS,

    pan: { x: 40, y: 40 },
    zoom: 1,
    selected: null,
    selectedEdge: null,
    drag: null,
    panning: null,
    linking: null,
    paletteOpen: false,

    saving: false,
    dirty: false,
    errors: [],

    init() {
        const g = clone(cfg.graph || { nodes: [], edges: [] });
        this.nodes = (g.nodes || []).map((n) => ({ ...n, data: { ...defaults(n.type), ...(n.data || {}) } }));
        this.edges = g.edges || [];

        if (!this.nodes.some((n) => n.type === 'trigger')) {
            this.nodes.unshift({ id: 'start', type: 'trigger', x: 60, y: 200, data: {} });
        }

        window.addEventListener('pointermove', (e) => this.onMove(e));
        window.addEventListener('pointerup', (e) => this.onUp(e));
        window.addEventListener('beforeunload', (e) => {
            if (this.dirty && !this.saving) {
                e.preventDefault();
                e.returnValue = '';
            }
        });

        this.$nextTick(() => {
            this.fit();
            this.$watch('nodes', () => (this.dirty = true));
            this.$watch('edges', () => {
                this.dirty = true;
                this.errors = this.errors.filter((e) => !/connect/i.test(e)); // re-wired → drop stale "connect …" errors
            });
            this.$watch('s', () => (this.dirty = true));
        });
    },

    // ------------------------------------------------------------ helpers
    node(id) {
        return this.nodes.find((n) => n.id === id);
    },
    get current() {
        return this.node(this.selected);
    },
    tone(type) {
        return TONES[META[type].tone];
    },
    iconSvg(name) {
        return this.$root.querySelector(`[data-icon="${name}"]`)?.innerHTML || '';
    },
    title(node) {
        if (node.type === 'trigger') return 'Trigger';
        const same = this.nodes.filter((n) => n.type === node.type);
        return `${META[node.type].label} ${same.indexOf(node) + 1}`;
    },
    flowName(id) {
        return (this.targets[this.s.whatsapp_account_id] || []).find((f) => f.id === Number(id))?.name;
    },
    gotoTargets() {
        return (this.targets[this.s.whatsapp_account_id] || []).filter((f) => f.id !== cfg.flowId);
    },
    preview(node) {
        const d = node.data;
        switch (node.type) {
            case 'trigger':
                if (this.s.trigger_type === 'keyword') return `Keyword (${(this.matches[this.s.match_type] || '').toLowerCase()}): ${this.s.keywords || '—'}`;
                return this.triggers[this.s.trigger_type] || '';
            case 'text':
                return d.text || 'Click to write the message…';
            case 'menu':
                return d.text || 'Menu message…';
            case 'question':
                return d.text || 'Ask a question…';
            case 'condition':
                return 'Checked top to bottom — the first rule that matches wins.';
            case 'image':
                return d.caption || d.url || 'Add a public image link…';
            case 'document':
                return d.filename || d.caption || d.url || 'Add a public document link…';
            case 'handoff':
                return d.text || 'Pause the bot — an agent replies.';
            case 'goto':
                return d.flow_id ? `→ ${this.flowName(d.flow_id) || 'Flow #' + d.flow_id}` : 'Choose a flow…';
        }
        return '';
    },
    hasOut(node) {
        return ['trigger', 'text', 'image', 'document'].includes(node.type);
    },
    hasError(node) {
        const t = this.title(node);
        return this.errors.some((e) => e.startsWith(t + ':') || e.startsWith(t + ',') || (node.type === 'trigger' && e.includes('Trigger')));
    },
    isConnected(nodeId, port) {
        return this.edges.some((e) => e.from === nodeId && e.port === port);
    },
    isRowNode(node) {
        return ROW_NODES.includes(node.type);
    },
    /** Output rows (each with its own dot) for menu / question / condition nodes. */
    rows(node) {
        if (node.type === 'menu') return node.data.options.map((o) => ({ id: o.id, label: o.title || 'Option', required: true }));
        if (node.type === 'question')
            return [
                { id: 'out', label: `✓ Answer → {{${node.data.var || 'answer'}}}` },
                { id: 'invalid', label: '✗ No valid answer' },
            ];
        if (node.type === 'condition') return [...node.data.rules.map((r, i) => ({ id: r.id, label: `${i + 1}. ${this.ruleLabel(r)}` })), { id: 'else', label: 'Otherwise' }];
        return [];
    },
    nodeHeight(node) {
        return ROWS_TOP + ROW * this.rows(node).length;
    },
    ruleLabel(r) {
        if (r.kind === 'time') return `${(r.days || []).map((d) => DAYS[d - 1]).join(',') || '—'} ${r.from}–${r.to}`;
        if (r.kind === 'tag') return `Tag ${r.op === 'has_not' ? 'is not' : 'is'} “${r.value || '…'}”`;
        if (r.kind === 'new_contact') return r.op === 'is_not' ? 'Returning contact' : 'New contact';
        const subject = r.subject === 'var' ? `{{${r.var || '…'}}}` : 'Message';
        const op = (this.textOps[r.op] || r.op).toLowerCase();
        return ['is_empty', 'not_empty'].includes(r.op) ? `${subject} ${op}` : `${subject} ${op} “${r.value || '…'}”`;
    },
    /** Variables saved by Question nodes in this flow (+ built-ins). */
    knownVars() {
        const vars = this.nodes.filter((n) => n.type === 'question' && n.data.var).map((n) => n.data.var);
        return [...new Set([...vars, 'contact_name', 'phone'])];
    },
    accountTags() {
        return this.tags[this.s.whatsapp_account_id] || [];
    },
    dropPort(nodeId, port) {
        this.edges = this.edges.filter((e) => !(e.from === nodeId && e.port === port));
    },
    addRule() {
        const n = this.current;
        if (n && n.data.rules.length < 8) n.data.rules.push(newRule());
    },
    removeRule(i) {
        const n = this.current;
        const [r] = n.data.rules.splice(i, 1);
        this.dropPort(n.id, r.id);
    },
    moveRule(i, dir) {
        const list = this.current.data.rules;
        const j = i + dir;
        if (j < 0 || j >= list.length) return;
        const [r] = list.splice(i, 1);
        list.splice(j, 0, r);
    },

    // ------------------------------------------------------------ geometry
    portPos(node, port) {
        if (port === 'in') return { x: node.x, y: node.y + HEAD_CENTER };
        if (this.isRowNode(node)) {
            const i = this.rows(node).findIndex((r) => r.id === port);
            return { x: node.x + W, y: node.y + ROWS_TOP + ROW * Math.max(i, 0) + ROW / 2 };
        }
        return { x: node.x + W, y: node.y + HEAD_CENTER };
    },
    curve(a, b) {
        const dx = Math.max(60, Math.abs(b.x - a.x) * 0.5);
        return { d: `M${a.x},${a.y} C${a.x + dx},${a.y} ${b.x - dx},${b.y} ${b.x},${b.y}`, c1: { x: a.x + dx, y: a.y }, c2: { x: b.x - dx, y: b.y } };
    },
    edgeKey(e) {
        return `${e.from}|${e.port}`;
    },
    edgesSvg() {
        let out = '';
        for (const e of this.edges) {
            const from = this.node(e.from);
            const to = this.node(e.to);
            if (!from || !to) continue;
            const { d } = this.curve(this.portPos(from, e.port), this.portPos(to, 'in'));
            const key = this.edgeKey(e);
            const active = this.selectedEdge === key || this.selected === e.from || this.selected === e.to;
            out += `<path d="${d}" fill="none" stroke="transparent" stroke-width="16" data-edge="${key}" style="cursor:pointer;pointer-events:stroke"></path>`;
            out += `<path d="${d}" fill="none" stroke-width="${active ? 3 : 2}" class="${active ? 'stroke-brand-500' : 'stroke-slate-400 dark:stroke-slate-500'}" style="pointer-events:none"></path>`;
        }
        if (this.linking) {
            const from = this.node(this.linking.from);
            if (from) {
                const { d } = this.curve(this.portPos(from, this.linking.port), { x: this.linking.x, y: this.linking.y });
                out += `<path d="${d}" fill="none" stroke-width="2" stroke-dasharray="6 5" class="stroke-brand-500" style="pointer-events:none"></path>`;
            }
        }
        return out;
    },
    edgeMid() {
        const e = this.edges.find((x) => this.edgeKey(x) === this.selectedEdge);
        if (!e) return null;
        const a = this.portPos(this.node(e.from), e.port);
        const b = this.portPos(this.node(e.to), 'in');
        const { c1, c2 } = this.curve(a, b);
        return { x: (a.x + 3 * c1.x + 3 * c2.x + b.x) / 8, y: (a.y + 3 * c1.y + 3 * c2.y + b.y) / 8 };
    },
    toWorld(clientX, clientY) {
        const r = this.$refs.canvas.getBoundingClientRect();
        return { x: (clientX - r.left - this.pan.x) / this.zoom, y: (clientY - r.top - this.pan.y) / this.zoom };
    },
    gridStyle() {
        const size = GRID * this.zoom;
        return `background-size:${size}px ${size}px;background-position:${this.pan.x}px ${this.pan.y}px`;
    },

    // ------------------------------------------------------------ pointer
    startDrag(e, node) {
        if (e.button !== 0) return;
        this.select(node.id);
        this.drag = { id: node.id, sx: e.clientX, sy: e.clientY, ox: node.x, oy: node.y, moved: false };
    },
    startPan(e) {
        if (e.button !== 0 && e.button !== 1) return;
        const edge = e.target?.dataset?.edge;
        if (edge) {
            this.selectedEdge = edge;
            this.selected = null;
            return;
        }
        this.selected = null;
        this.selectedEdge = null;
        this.paletteOpen = false;
        this.panning = { sx: e.clientX, sy: e.clientY, ox: this.pan.x, oy: this.pan.y };
    },
    startLink(e, node, port) {
        if (e.button !== 0) return;
        const p = this.portPos(node, port);
        this.linking = { from: node.id, port, x: p.x, y: p.y };
    },
    onMove(e) {
        if (this.drag) {
            const n = this.node(this.drag.id);
            if (!n) return;
            const dx = (e.clientX - this.drag.sx) / this.zoom;
            const dy = (e.clientY - this.drag.sy) / this.zoom;
            if (Math.abs(dx) + Math.abs(dy) > 2) this.drag.moved = true;
            n.x = this.drag.ox + dx;
            n.y = this.drag.oy + dy;
        } else if (this.panning) {
            this.pan.x = this.panning.ox + (e.clientX - this.panning.sx);
            this.pan.y = this.panning.oy + (e.clientY - this.panning.sy);
        } else if (this.linking) {
            const p = this.toWorld(e.clientX, e.clientY);
            this.linking.x = p.x;
            this.linking.y = p.y;
        }
    },
    onUp(e) {
        if (this.drag) {
            const n = this.node(this.drag.id);
            if (n && this.drag.moved) {
                n.x = Math.round(n.x / GRID) * GRID;
                n.y = Math.round(n.y / GRID) * GRID;
            } else if (n) {
                this.ensureVisible(n.id); // plain click → keep it in view next to the panel
            }
            this.drag = null;
        }
        if (this.linking) {
            const el = document.elementFromPoint(e.clientX, e.clientY)?.closest('[data-node-id]');
            if (el) this.connect(this.linking.from, this.linking.port, el.dataset.nodeId);
            this.linking = null;
        }
        this.panning = null;
    },
    onWheel(e) {
        const r = this.$refs.canvas.getBoundingClientRect();
        this.zoomAt(e.deltaY < 0 ? 1.1 : 1 / 1.1, e.clientX - r.left, e.clientY - r.top);
    },
    zoomAt(factor, mx, my) {
        const z = Math.min(1.6, Math.max(0.3, this.zoom * factor));
        const wx = (mx - this.pan.x) / this.zoom;
        const wy = (my - this.pan.y) / this.zoom;
        this.zoom = z;
        this.pan.x = mx - wx * z;
        this.pan.y = my - wy * z;
    },
    zoomBy(factor) {
        const r = this.$refs.canvas.getBoundingClientRect();
        this.zoomAt(factor, r.width / 2, r.height / 2);
    },
    fit() {
        const r = this.$refs.canvas?.getBoundingClientRect();
        if (!r || !this.nodes.length) return;
        const xs = this.nodes.map((n) => n.x);
        const ys = this.nodes.map((n) => n.y);
        const h = (n) => this.nodeHeight(n);
        const minX = Math.min(...xs) - 40;
        const minY = Math.min(...ys) - 40;
        const maxX = Math.max(...this.nodes.map((n) => n.x + W)) + 40;
        const maxY = Math.max(...this.nodes.map((n) => n.y + h(n))) + 40;
        this.zoom = Math.min(1.1, Math.max(0.3, Math.min(r.width / (maxX - minX), r.height / (maxY - minY))));
        this.pan.x = (r.width - (maxX - minX) * this.zoom) / 2 - minX * this.zoom;
        this.pan.y = (r.height - (maxY - minY) * this.zoom) / 2 - minY * this.zoom;
    },

    // ------------------------------------------------------------ editing
    select(id) {
        this.selected = id;
        this.selectedEdge = null;
    },
    /** Pan so the node is not hidden behind the settings panel / canvas edge. */
    ensureVisible(id) {
        this.$nextTick(() =>
            setTimeout(() => {
                const n = this.node(id);
                const c = this.$refs.canvas;
                if (!n || !c) return;
                const r = c.getBoundingClientRect();
                const h = this.nodeHeight(n);
                const m = 40;
                const left = this.pan.x + n.x * this.zoom;
                const right = left + W * this.zoom;
                const top = this.pan.y + n.y * this.zoom;
                const bottom = top + h * this.zoom;
                if (right > r.width - m) this.pan.x -= right - (r.width - m);
                else if (left < m) this.pan.x += m - left;
                if (bottom > r.height - m) this.pan.y -= Math.min(bottom - (r.height - m), top - m);
                else if (top < m) this.pan.y += m - top;
            }, 220),
        );
    },
    connect(from, port, to) {
        const target = this.node(to);
        if (!target || from === to || target.type === 'trigger') return;
        this.edges = this.edges.filter((e) => !(e.from === from && e.port === port));
        this.edges.push({ from, port, to });
    },
    deleteEdge(key = this.selectedEdge) {
        this.edges = this.edges.filter((e) => this.edgeKey(e) !== key);
        this.selectedEdge = null;
    },
    addNode(type, x = null, y = null) {
        if (!META[type] || type === 'trigger') return;
        if (this.nodes.length > cfg.maxNodes) {
            window.toast(`A flow can have at most ${cfg.maxNodes} nodes.`, 'error');
            return;
        }
        const source = this.current;
        const freeOut = source && this.hasOut(source) && !this.isConnected(source.id, 'out');
        let autoLink = false;
        if (x === null) {
            if (freeOut) {
                autoLink = true;
                x = source.x + W + 80;
                y = source.y;
            } else {
                const r = this.$refs.canvas.getBoundingClientRect();
                const c = this.toWorld(r.left + r.width / 2, r.top + r.height / 2);
                x = c.x - W / 2;
                y = c.y - 40;
            }
        }
        const node = { id: uid('n'), type, x: Math.round(x / GRID) * GRID, y: Math.round(y / GRID) * GRID, data: defaults(type) };
        this.nodes.push(node);
        if (autoLink) this.connect(source.id, 'out', node.id);
        this.select(node.id);
        this.ensureVisible(node.id);
        this.paletteOpen = false;
    },
    onDrop(e) {
        const type = e.dataTransfer.getData('text/bot-node');
        if (!type) return;
        const p = this.toWorld(e.clientX, e.clientY);
        this.addNode(type, p.x - W / 2, p.y - HEAD_CENTER);
    },
    removeNode(id = this.selected) {
        const n = this.node(id);
        if (!n || n.type === 'trigger') return;
        this.nodes = this.nodes.filter((x) => x.id !== id);
        this.edges = this.edges.filter((e) => e.from !== id && e.to !== id);
        this.selected = null;
    },
    duplicate() {
        const n = this.current;
        if (!n || n.type === 'trigger') return;
        const copy = { ...clone(n), id: uid('n'), x: n.x + 40, y: n.y + 60 };
        if (copy.type === 'menu') copy.data.options = copy.data.options.map((o) => ({ ...o, id: uid('o') }));
        if (copy.type === 'condition') copy.data.rules = copy.data.rules.map((r) => ({ ...r, id: uid('r') }));
        this.nodes.push(copy);
        this.select(copy.id);
    },
    addOption() {
        const n = this.current;
        if (!n || n.data.options.length >= 10) return;
        n.data.options.push({ id: uid('o'), title: `Option ${n.data.options.length + 1}`, description: '' });
    },
    removeOption(i) {
        const n = this.current;
        const [o] = n.data.options.splice(i, 1);
        this.edges = this.edges.filter((e) => !(e.from === n.id && e.port === o.id));
    },
    moveOption(i, dir) {
        const list = this.current.data.options;
        const j = i + dir;
        if (j < 0 || j >= list.length) return;
        const [o] = list.splice(i, 1);
        list.splice(j, 0, o);
    },
    onKey(e) {
        const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName);
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
            e.preventDefault();
            this.save();
            return;
        }
        if (typing) return;
        if (e.key === 'Delete' || e.key === 'Backspace') {
            if (this.selectedEdge) this.deleteEdge();
            else if (this.selected) this.removeNode();
        } else if (e.key === 'Escape') {
            this.selected = null;
            this.selectedEdge = null;
            this.linking = null;
        }
    },

    // ------------------------------------------------------------ save
    async save() {
        if (this.saving) return;
        this.saving = true;
        this.errors = [];
        const body = {
            ...this.s,
            is_active: this.s.is_active ? 1 : 0,
            graph: JSON.stringify({ nodes: this.nodes, edges: this.edges }),
        };
        try {
            const res = await window.http(cfg.saveUrl, { method: cfg.method, body });
            this.dirty = false;
            if (res.redirect && !cfg.editing) {
                window.location = res.redirect;
                return;
            }
            window.toast(res.message || 'Saved.');
        } catch (e) {
            if (e.status === 422 && e.data?.errors) {
                this.errors = Object.values(e.data.errors).flat();
                window.toast('Please fix the highlighted nodes.', 'error');
            } else {
                window.toast(e.message || 'Could not save.', 'error');
            }
        } finally {
            this.saving = false;
        }
    },
});
