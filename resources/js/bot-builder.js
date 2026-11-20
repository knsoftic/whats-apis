/**
 * Bot flow builder (Alpine component "botBuilder").
 * Steps are edited as an array and posted as JSON in a hidden "steps" field.
 */
const blank = {
    text: () => ({ type: 'text', text: '' }),
    menu: () => ({ type: 'menu', text: '', footer: '', button_label: '', options: [{ title: '', description: '', flow_id: '' }] }),
    image: () => ({ type: 'image', url: '', caption: '' }),
    document: () => ({ type: 'document', url: '', caption: '', filename: '' }),
    handoff: () => ({ type: 'handoff', text: 'Thanks! An agent will reply shortly.' }),
};

export default function botBuilder(config) {
    return {
        steps: (config.steps && config.steps.length ? config.steps : [blank.text()]).map((s) =>
            s.type === 'menu' ? { footer: '', button_label: '', ...s, options: (s.options || []).map((o) => ({ description: '', ...o, flow_id: o.flow_id ? String(o.flow_id) : '' })) } : { ...s },
        ),
        targetsByAccount: config.targets || {},
        accountId: String(config.accountId || ''),
        flowId: config.flowId || null,
        trigger: config.trigger || 'keyword',
        maxSteps: config.maxSteps || 8,

        get targets() {
            return this.targetsByAccount[this.accountId] || [];
        },
        get json() {
            return JSON.stringify(this.steps);
        },
        add(type) {
            if (this.steps.length >= this.maxSteps) return;
            this.steps.push(blank[type]());
        },
        remove(i) {
            this.steps.splice(i, 1);
        },
        move(i, dir) {
            const j = i + dir;
            if (j < 0 || j >= this.steps.length) return;
            const [s] = this.steps.splice(i, 1);
            this.steps.splice(j, 0, s);
        },
        addOption(step) {
            if (step.options.length < 10) step.options.push({ title: '', description: '', flow_id: '' });
        },
        removeOption(step, j) {
            step.options.splice(j, 1);
        },
        titleMax(step) {
            return step.options.length <= 3 ? 20 : 24;
        },
        menuKind(step) {
            return step.options.length <= 3 ? 'Reply buttons' : 'List message';
        },
        label(type) {
            return { text: 'Text message', menu: 'Menu (buttons / list)', image: 'Image', document: 'Document', handoff: 'Handoff to agent' }[type] || type;
        },
    };
}
