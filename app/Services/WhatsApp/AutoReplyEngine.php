<?php

namespace App\Services\WhatsApp;

use App\Events\MessageUpdated;
use App\Exceptions\PlanLimitException;
use App\Jobs\SendWhatsAppMessage;
use App\Models\AutoReply;
use App\Models\BotFlow;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\PlanGate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Auto Replies — always reply / auto reply / quick action buttons.
 *
 * Runs on its own, next to Bot Flows (own rules, own job, own button ids "ar:"),
 * and decides everything from this app's chat history:
 *   - first message  → the customer never wrote to this number before
 *   - returning      → they wrote before (optionally: only after N quiet hours)
 *   - keyword / every message, filtered by message type and time of day
 *   - "while chatting" → also answer when an agent replied recently (off by default)
 * The highest-priority matching rule answers — one auto reply per customer message.
 * Bot Flows' pause (agent replied / handoff) does not apply here.
 */
class AutoReplyEngine
{
    public function __construct(private readonly PlanGate $planGate) {}

    public function handle(Message $inbound): ?AutoReply
    {
        if ($inbound->direction !== Message::IN || in_array($inbound->type, ['reaction', 'system', 'unsupported'], true)) {
            return null;
        }

        $account = $inbound->account;
        $conversation = $inbound->conversation;

        if (! $account?->is_active || ! $conversation || $conversation->contact?->blocked_at || ! $this->planGate->botsAllowed($account)) {
            return null;
        }

        $rules = AutoReply::where('whatsapp_account_id', $account->id)->active()
            ->orderByDesc('priority')->orderBy('id')->get();

        if ($rules->isEmpty()) {
            return null;
        }

        $payload = (string) ($inbound->content['reply']['id'] ?? $inbound->content['payload'] ?? '');

        // Quick action button tapped → its answer (always, it was our question).
        if (str_starts_with($payload, AutoReply::PAYLOAD_PREFIX)) {
            return $this->buttonTapped($rules, $payload, $conversation);
        }

        // A Bot Flow menu tap belongs to the Bot Flow.
        if (str_starts_with($payload, BotFlow::PAYLOAD_PREFIX)) {
            return null;
        }

        foreach ($rules as $rule) {
            if ($this->matches($rule, $inbound, $conversation)) {
                $this->reply($rule, $conversation, (string) $rule->reply_text, $rule->buttonList());

                return $rule;
            }
        }

        return null;
    }

    public function matches(AutoReply $rule, Message $inbound, Conversation $conversation): bool
    {
        // Message type (voice, picture, …) — empty = any.
        $types = array_values(array_filter((array) $rule->message_types));
        if ($types && ! collect($types)->contains(fn ($t) => BotEngine::messageTypeMatches($inbound, (string) $t))) {
            return false;
        }

        if ($rule->hours_mode !== 'always' && $this->inHours($rule) !== ($rule->hours_mode === 'inside')) {
            return false;
        }

        if (! $rule->while_chatting && $this->agentIsChatting($conversation)) {
            return false;
        }

        if ($rule->cooldown_minutes > 0 && Message::where('conversation_id', $conversation->id)
            ->where('content->auto_reply', $rule->id)
            ->where('created_at', '>', now()->subMinutes($rule->cooldown_minutes))->exists()) {
            return false;
        }

        // Read the chat box: did this customer write to this number before?
        $previous = Message::where('whatsapp_account_id', $conversation->whatsapp_account_id)
            ->where('contact_id', $inbound->contact_id)
            ->where('direction', Message::IN)
            ->where('id', '<', $inbound->id)
            ->latest('id')
            ->first();

        return match ($rule->trigger) {
            'first_message' => $previous === null,
            'returning' => $previous !== null && ($rule->gap_hours === 0
                || ($previous->wa_timestamp ?? $previous->created_at)->lte(($inbound->wa_timestamp ?? now())->copy()->subHours($rule->gap_hours))),
            'keyword' => $rule->matchesKeyword((string) $inbound->body),
            default => true, // always
        };
    }

    /** An agent (a person, not a bot / auto reply) wrote in this chat recently. */
    private function agentIsChatting(Conversation $conversation): bool
    {
        return Message::where('conversation_id', $conversation->id)
            ->where('direction', Message::OUT)
            ->whereNotNull('sent_by_user_id')
            ->where('created_at', '>=', now()->subMinutes((int) config('whatsapp.auto_reply.agent_active_minutes', 30)))
            ->exists();
    }

    private function inHours(AutoReply $rule): bool
    {
        $now = now();

        if (! in_array($now->dayOfWeekIso, array_map('intval', (array) $rule->days), true)) {
            return false;
        }

        $t = $now->format('H:i');
        [$from, $to] = [(string) ($rule->from_time ?: '00:00'), (string) ($rule->to_time ?: '23:59')];

        return $from <= $to ? ($t >= $from && $t < $to) : ($t >= $from || $t < $to); // overnight window
    }

    /** @param  \Illuminate\Support\Collection<int, AutoReply>  $rules */
    private function buttonTapped($rules, string $payload, Conversation $conversation): ?AutoReply
    {
        [$ruleId, $buttonId] = array_pad(explode(':', substr($payload, strlen(AutoReply::PAYLOAD_PREFIX)), 2), 2, '');
        $rule = $rules->firstWhere('id', (int) $ruleId);
        $button = $rule ? collect($rule->buttonList())->firstWhere('id', $buttonId) : null;

        if (! $button || blank($button['reply'] ?? null)) {
            return null;
        }

        $this->reply($rule, $conversation, (string) $button['reply'], [], $button['title'] ?? null);

        return $rule;
    }

    /**
     * @param  list<array{id: string, title: string, reply: string}>  $buttons
     */
    private function reply(AutoReply $rule, Conversation $conversation, string $text, array $buttons, ?string $button = null): void
    {
        $account = $conversation->account;

        try {
            $this->planGate->ensureCanSend($account);
        } catch (PlanLimitException $e) {
            Log::info('Auto reply stopped by plan limit', ['rule' => $rule->id, 'reason' => $e->getMessage()]);

            return;
        }

        $contact = $conversation->contact;
        $text = trim($this->fill($text, $account, $contact));

        if ($text === '') {
            return;
        }

        $meta = array_filter(['auto_reply' => $rule->id, 'auto_reply_name' => $rule->name, 'auto_reply_button' => $button]);
        $attributes = $buttons
            ? [
                'type' => 'interactive',
                'body' => Str::limit($text, 1024, ''),
                'content' => $meta + ['interactive' => [
                    'type' => 'button',
                    'body' => ['text' => Str::limit($text, 1024, '')],
                    'action' => ['buttons' => array_map(fn ($b) => [
                        'type' => 'reply',
                        'reply' => ['id' => AutoReply::PAYLOAD_PREFIX.$rule->id.':'.$b['id'], 'title' => Str::limit((string) $b['title'], 20, '')],
                    ], array_slice($buttons, 0, AutoReply::MAX_BUTTONS))],
                ]],
            ]
            : [
                'type' => 'text',
                'body' => Str::limit($text, 4096, ''),
                'content' => $meta + ['preview_url' => (bool) preg_match('~https?://~i', $text)],
            ];

        $message = Message::create($attributes + [
            'whatsapp_account_id' => $account->id,
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'direction' => Message::OUT,
            'sender' => Contact::normalizeWaId((string) ($account->display_phone_number ?: $account->phone_number_id)),
            'recipient' => $contact->wa_id,
            'status' => Message::STATUS_PENDING,
            'wa_timestamp' => now(),
        ]);

        $conversation->forceFill([
            'last_message_preview' => $message->previewText(),
            'last_message_direction' => Message::OUT,
            'last_message_at' => now(),
        ])->save();

        $rule->forceFill(['runs_count' => $rule->runs_count + 1, 'last_run_at' => now()])->save();

        SendWhatsAppMessage::dispatch($message->id)->onQueue(config('whatsapp.queue'));
        MessageUpdated::dispatch($message);
    }

    /** {{contact_name}}, {{phone}}, {{business_name}} */
    public function fill(string $text, $account, ?Contact $contact): string
    {
        $vars = [
            'contact_name' => (string) ($contact?->name ?: $contact?->profile_name ?: 'there'),
            'phone' => (string) ($contact?->phone ?? ''),
            'business_name' => (string) ($account->verified_name ?: $account->name),
        ];

        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/i', fn ($m) => $vars[strtolower($m[1])] ?? '', $text);
    }
}
