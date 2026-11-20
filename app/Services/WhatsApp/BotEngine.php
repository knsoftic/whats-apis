<?php

namespace App\Services\WhatsApp;

use App\Events\MessageUpdated;
use App\Exceptions\PlanLimitException;
use App\Jobs\SendWhatsAppMessage;
use App\Models\BotFlow;
use App\Models\BotFlowRun;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\PlanGate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Runs bot flows for an incoming message.
 *
 * Choice order:  1. menu button / list row tapped ("bf:{id}")
 *                2. keyword flows (highest priority first)
 *                3. welcome flow (contact's first message)
 *                4. fallback flow
 * Never runs when the chat's bot is paused (agent replied / handoff), the
 * number is disabled, the owner's plan has no bots, or the daily limit is hit.
 * Steps are sent one after another, in order, inside one queued job.
 */
class BotEngine
{
    public function __construct(private readonly PlanGate $planGate) {}

    public function handle(Message $inbound): ?BotFlow
    {
        if ($inbound->direction !== Message::IN || in_array($inbound->type, ['reaction', 'system', 'unsupported'], true)) {
            return null;
        }

        $account = $inbound->account;
        $conversation = $inbound->conversation;

        if (! $account?->is_active || ! $conversation || $conversation->isBotPaused()) {
            return null;
        }

        $flows = BotFlow::where('whatsapp_account_id', $account->id)->active()
            ->orderByDesc('priority')->orderBy('id')->get();

        if ($flows->isEmpty() || ! $this->planGate->botsAllowed($account)) {
            return null;
        }

        $flow = $this->pickFlow($flows, $inbound, $conversation);

        if (! $flow || $this->inCooldown($flow, $conversation)) {
            return null;
        }

        $this->run($flow, $conversation, $inbound);

        return $flow;
    }

    /**
     * @param  Collection<int, BotFlow>  $flows
     */
    public function pickFlow(Collection $flows, Message $inbound, Conversation $conversation): ?BotFlow
    {
        // 1. Menu option tapped
        $payload = (string) ($inbound->content['reply']['id'] ?? $inbound->content['payload'] ?? '');

        if (str_starts_with($payload, BotFlow::PAYLOAD_PREFIX)) {
            $target = $flows->firstWhere('id', (int) substr($payload, strlen(BotFlow::PAYLOAD_PREFIX)));

            if ($target) {
                return $target;
            }
        }

        // 2. Keywords (text, quick-reply button text, interactive reply title)
        $text = (string) ($inbound->body ?? '');

        if ($text !== '') {
            $keyword = $flows->first(fn (BotFlow $f) => $f->trigger_type === 'keyword' && $f->matches($text));

            if ($keyword) {
                return $keyword;
            }
        }

        // 3. Welcome — the contact's very first message to this number
        $welcome = $flows->firstWhere('trigger_type', 'welcome');

        if ($welcome && $conversation->messages()->where('direction', Message::IN)->count() === 1) {
            return $welcome;
        }

        // 4. Fallback
        return $flows->firstWhere('trigger_type', 'fallback');
    }

    private function inCooldown(BotFlow $flow, Conversation $conversation): bool
    {
        return $flow->cooldown_minutes > 0 && BotFlowRun::where('bot_flow_id', $flow->id)
            ->where('conversation_id', $conversation->id)
            ->where('created_at', '>', now()->subMinutes($flow->cooldown_minutes))
            ->exists();
    }

    public function run(BotFlow $flow, Conversation $conversation, ?Message $trigger = null): int
    {
        BotFlowRun::create(['bot_flow_id' => $flow->id, 'conversation_id' => $conversation->id, 'trigger_message_id' => $trigger?->id]);
        $flow->forceFill(['runs_count' => $flow->runs_count + 1, 'last_run_at' => now()])->save();

        $sent = 0;
        $steps = array_slice($flow->steps ?? [], 0, (int) config('whatsapp.bot.max_steps', 8));

        foreach ($steps as $step) {
            $type = $step['type'] ?? 'text';

            if ($type === 'handoff') {
                $conversation->forceFill([
                    'bot_paused_until' => now()->addHours((int) config('whatsapp.bot.handoff_hours', 24)),
                    'status' => Conversation::STATUS_OPEN,
                ])->save();

                if (filled($step['text'] ?? null) && $this->send($flow, $conversation, ['type' => 'text', 'text' => $step['text']])) {
                    $sent++;
                }

                break;
            }

            if (! $this->send($flow, $conversation, $step)) {
                break;
            }

            $sent++;
        }

        return $sent;
    }

    /**
     * Creates the outgoing message and delivers it immediately (keeps step order).
     *
     * @param  array<string, mixed>  $step
     */
    private function send(BotFlow $flow, Conversation $conversation, array $step): bool
    {
        $account = $conversation->account;

        try {
            $this->planGate->ensureCanSend($account);
        } catch (PlanLimitException $e) {
            Log::info('Bot stopped by plan limit', ['flow' => $flow->id, 'reason' => $e->getMessage()]);

            return false;
        }

        $attributes = $this->messageAttributes($flow, $step);

        if (! $attributes) {
            return true; // empty / unknown step → skip
        }

        $contact = $conversation->contact;

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

        SendWhatsAppMessage::dispatchSync($message->id);

        $message->refresh();
        MessageUpdated::dispatch($message);

        return $message->status !== Message::STATUS_FAILED;
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>|null
     */
    public function messageAttributes(BotFlow $flow, array $step): ?array
    {
        $meta = ['bot_flow_id' => $flow->id, 'bot_flow' => $flow->name];

        return match ($step['type'] ?? 'text') {
            'text' => filled($step['text'] ?? null) ? [
                'type' => 'text',
                'body' => Str::limit((string) $step['text'], 4096, ''),
                'content' => $meta + ['preview_url' => (bool) preg_match('~https?://~i', (string) $step['text'])],
            ] : null,

            'image', 'document' => filled($step['url'] ?? null) ? [
                'type' => $step['type'],
                'body' => filled($step['caption'] ?? null) ? Str::limit((string) $step['caption'], 1024, '') : null,
                'content' => $meta + array_filter([
                    'media_link' => (string) $step['url'],
                    'filename' => $step['type'] === 'document' ? ($step['filename'] ?? null) : null,
                ]),
            ] : null,

            'menu' => ! empty($step['options']) ? [
                'type' => 'interactive',
                'body' => Str::limit((string) ($step['text'] ?? ''), 1024, ''),
                'content' => $meta + ['interactive' => $this->menuPayload($step)],
            ] : null,

            default => null,
        };
    }

    /**
     * ≤ 3 options → reply buttons; 4–10 → list message.
     *
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    public function menuPayload(array $step): array
    {
        $options = array_slice($step['options'], 0, 10);
        $body = ['text' => Str::limit((string) ($step['text'] ?? 'Please choose an option'), 1024, '')];
        $footer = filled($step['footer'] ?? null) ? ['footer' => ['text' => Str::limit((string) $step['footer'], 60, '')]] : [];

        if (count($options) <= 3) {
            return ['type' => 'button', 'body' => $body] + $footer + ['action' => ['buttons' => array_map(fn ($o) => [
                'type' => 'reply',
                'reply' => ['id' => BotFlow::PAYLOAD_PREFIX.$o['flow_id'], 'title' => Str::limit((string) $o['title'], 20, '')],
            ], $options)]];
        }

        return ['type' => 'list', 'body' => $body] + $footer + ['action' => [
            'button' => Str::limit((string) ($step['button_label'] ?? '') ?: 'Choose', 20, ''),
            'sections' => [[
                'title' => 'Options',
                'rows' => array_map(fn ($o) => array_filter([
                    'id' => BotFlow::PAYLOAD_PREFIX.$o['flow_id'],
                    'title' => Str::limit((string) $o['title'], 24, ''),
                    'description' => filled($o['description'] ?? null) ? Str::limit((string) $o['description'], 72, '') : null,
                ]), $options),
            ]],
        ]];
    }
}
