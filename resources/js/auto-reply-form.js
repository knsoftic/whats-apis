/** Auto reply form (Alpine component "autoReplyForm"): live preview + quick action buttons. */
export default function autoReplyForm(s) {
    return {
        ...s,
        addButton() {
            if (this.buttons.length < 3) this.buttons.push({ id: '', title: '', reply: '' });
        },
        preview() {
            return (this.reply_text || '')
                .replace(/\{\{\s*contact_name\s*\}\}/gi, 'Ali')
                .replace(/\{\{\s*business_name\s*\}\}/gi, this.business)
                .replace(/\{\{\s*phone\s*\}\}/gi, '+92 300 1234567');
        },
        explain() {
            const when = {
                first_message: 'Sent once, to a customer\'s very first message.',
                returning: this.gap_hours > 0 ? `Sent when a returning customer writes after ${this.gap_hours} quiet hour(s).` : 'Sent to every message of a returning customer.',
                keyword: `Sent when a message ${this.match_type === 'exact' ? 'is exactly' : (this.match_type === 'starts_with' ? 'starts with' : 'contains')}: ${this.keywords || '…'}`,
                always: 'Sent to every message.',
            }[this.trigger] || '';
            const types = this.message_types.length ? ` Only for: ${this.message_types.join(', ')}.` : '';
            const time = this.hours_mode === 'always' ? '' : ` ${this.hours_mode === 'inside' ? 'During' : 'Outside'} ${this.from_time}–${this.to_time}.`;
            const chat = this.while_chatting ? ' Also while an agent is chatting.' : ' Quiet while an agent is chatting.';
            return when + types + time + chat;
        },
    };
}
