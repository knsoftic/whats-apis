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
use App\Support\BotGraph;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Runs bot flows for an incoming message.
 *
 * Choice order:  1. menu button / list row tapped ("bf:{flow}" or "bf:{flow}:{node}:{option}")
 *                   (a pending Question node takes the message as its answer before 2.)
 *                2. "Any message" flows — every message of any type (text, voice,
 *                   picture, video, file, sticker, location…), first or not, keyword
 *                   match or not. One in its cooldown is skipped → the next choices.
 *                3. keyword flows (highest priority first)
 *                4. welcome flow (contact's first message)
 *                5. fallback flow
 * Never runs when the chat's bot is paused (agent replied / handoff), the
 * number is disabled, the owner's plan has no bots, or the daily limit is hit.
 *
 * Visual flows walk the node graph from the Trigger (or from the tapped menu
 * option) along the connections; a Menu node sends its buttons and waits for
 * the customer's tap. Older flows send their linear steps in order.
 */
class BotEngine
{
    /** Most "Go to flow" jumps in one run (stops accidental loops). */
    private const MAX_JUMPS = 5;

    public function __construct(private readonly PlanGate $planGate) {}

    public function handle(Message $inbound): ?BotFlow
    {
        if ($inbound->direction !== Message::IN || in_array($inbound->type, ['reaction', 'system', 'unsupported'], true)) {
            return null;
        }

        // A tapped Auto Reply quick action button ("ar:…") belongs to Auto Replies, not to a flow.
        if (str_starts_with((string) ($inbound->content['reply']['id'] ?? $inbound->content['payload'] ?? ''), \App\Models\AutoReply::PAYLOAD_PREFIX)) {
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

        // Waiting for the answer to a Question node? This message is the answer
        // (a tapped bot menu button still wins).
        if (! $this->isBotButton($inbound) && ($answered = $this->answerQuestion($flows, $inbound, $conversation))) {
            return $answered;
        }

        [$flow, $node, $port, $tapped] = $this->resolve($flows, $inbound, $conversation, skipAnyFlows: fn (BotFlow $f) => $this->inCooldown($f, $conversation));

        // A tapped menu button always answers; cooldown is for keyword / welcome / fallback.
        if (! $flow || (! $tapped && $this->inCooldown($flow, $conversation))) {
            return null;
        }

        if ($tapped) {
            $this->setWaiting($conversation, null);
        }

        $this->run($flow, $conversation, $inbound, $node, $port);

        return $flow;
    }

    private function isBotButton(Message $inbound): bool
    {
        return str_starts_with((string) ($inbound->content['reply']['id'] ?? $inbound->content['payload'] ?? ''), BotFlow::PAYLOAD_PREFIX);
    }

    // ------------------------------------------------------------------ Question

    /**
     * Treats the incoming message as the answer to the pending Question node.
     * Valid → save it (variable + optional contact field) and continue on "out".
     * Invalid → ask again; after the last attempt continue on "invalid".
     *
     * @param  Collection<int, BotFlow>  $flows
     */
    private function answerQuestion(Collection $flows, Message $inbound, Conversation $conversation): ?BotFlow
    {
        $state = $conversation->bot_state ?? [];
        $waiting = $state['waiting'] ?? null;

        if (! $waiting) {
            return null;
        }

        $flow = $flows->firstWhere('id', (int) ($waiting['flow_id'] ?? 0));
        $node = $flow?->isVisual() ? BotGraph::node($flow->graph, (string) ($waiting['node'] ?? '')) : null;
        $expired = Carbon::parse($waiting['at'] ?? '2000-01-01')->addMinutes((int) config('whatsapp.bot.answer_timeout_minutes', 1440))->isPast();

        if (! $node || $node['type'] !== 'question' || $expired) {
            $this->setWaiting($conversation, null);

            return null; // question gone / too old → handle as a normal message
        }

        $data = $node['data'];
        $answer = trim((string) ($inbound->body ?? ''));

        if (self::validAnswer($answer, (string) ($data['validate'] ?? 'any'))) {
            $state['vars'][$data['var']] = Str::limit($answer, 500, '');
            $state['waiting'] = null;
            $conversation->forceFill(['bot_state' => $state])->save();
            $this->saveToContact($conversation, (string) ($data['save_to'] ?? 'none'), $answer);
            $this->runGraph($flow, $conversation, $inbound, $node['id'], 'out');

            return $flow;
        }

        $attempts = (int) ($waiting['attempts'] ?? 0) + 1;

        if ($attempts >= (int) ($data['attempts'] ?? 2)) {
            $this->setWaiting($conversation, null);
            $this->runGraph($flow, $conversation, $inbound, $node['id'], 'invalid');

            return $flow;
        }

        $state['waiting']['attempts'] = $attempts;
        $conversation->forceFill(['bot_state' => $state])->save();

        $retry = filled($data['retry_text'] ?? null) ? (string) $data['retry_text'] : match ($data['validate'] ?? 'any') {
            'number' => 'Please reply with a number.',
            'email' => 'Please reply with a valid email address.',
            'phone' => 'Please reply with a valid phone number.',
            default => 'Please type your answer.',
        };
        $this->send($flow, $conversation, ['type' => 'text', 'text' => $this->fill($retry, $this->vars($conversation))]);

        return $flow;
    }

    /** Condition "Message type": voice / picture / video / file / text / … of the incoming message. */
    public static function messageTypeMatches(?Message $message, string $type): bool
    {
        $t = (string) ($message?->type ?? '');

        return match ($type) {
            'voice' => $t === 'audio',
            'button' => in_array($t, ['button', 'interactive'], true),
            'media' => in_array($t, ['image', 'video', 'audio', 'document', 'sticker'], true),
            '' => false,
            default => $t === $type,
        };
    }

    public static function validAnswer(string $answer, string $type): bool
    {
        return match ($type) {
            'number' => is_numeric(str_replace([',', ' '], '', $answer)),
            'email' => (bool) filter_var($answer, FILTER_VALIDATE_EMAIL),
            'phone' => (bool) preg_match('/^\+?[0-9\s\-()]{7,20}$/', $answer) && strlen(preg_replace('/\D/', '', $answer)) >= 7,
            default => $answer !== '',
        };
    }

    private function saveToContact(Conversation $conversation, string $field, string $answer): void
    {
        $contact = $conversation->contact;

        if ($field === 'name' && $contact) {
            $contact->forceFill(['name' => Str::limit($answer, 100, '')])->save();
        } elseif ($field === 'email' && $contact && filter_var($answer, FILTER_VALIDATE_EMAIL)) {
            $contact->forceFill(['email' => Str::limit($answer, 150, '')])->save();
        }
    }

    /** @param  array<string, mixed>|null  $waiting */
    private function setWaiting(Conversation $conversation, ?array $waiting): void
    {
        $state = $conversation->bot_state ?? [];

        if (($state['waiting'] ?? null) === $waiting) {
            return;
        }

        $state['waiting'] = $waiting;
        $conversation->forceFill(['bot_state' => $state])->save();
    }

    /**
     * Saved answers + built-ins, for {{placeholders}} and conditions.
     *
     * @return array<string, string>
     */
    private function vars(Conversation $conversation): array
    {
        $contact = $conversation->contact;

        return [
            'contact_name' => (string) ($contact?->name ?: $contact?->profile_name ?: ''),
            'phone' => (string) ($contact?->phone ?? ''),
            ...($conversation->bot_state['vars'] ?? []),
        ];
    }

    /** Replaces {{variable}} placeholders (unknown → empty). */
    private function fill(string $text, array $vars): string
    {
        return preg_replace_callback('/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/i', fn ($m) => (string) ($vars[strtolower($m[1])] ?? ''), $text);
    }

    // ----------------------------------------------------------------- Condition

    /** Id of the first matching rule, or "else". */
    private function evaluate(array $node, Conversation $conversation, ?Message $trigger, array $vars): string
    {
        foreach ($node['data']['rules'] ?? [] as $rule) {
            if ($this->ruleMatches($rule, $conversation, $trigger, $vars)) {
                return (string) $rule['id'];
            }
        }

        return 'else';
    }

    /** @param  array<string, mixed>  $rule */
    public function ruleMatches(array $rule, Conversation $conversation, ?Message $trigger, array $vars): bool
    {
        switch ($rule['kind'] ?? 'text') {
            case 'time':
                $now = now();
                if (! in_array($now->dayOfWeekIso, $rule['days'] ?? [], true)) {
                    return false;
                }
                $t = $now->format('H:i');
                [$from, $to] = [(string) ($rule['from'] ?? '00:00'), (string) ($rule['to'] ?? '23:59')];

                return $from <= $to ? ($t >= $from && $t < $to) : ($t >= $from || $t < $to); // overnight window

            case 'tag':
                $has = (bool) $conversation->contact?->tags()->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $rule['value'])])->exists();

                return ($rule['op'] ?? 'has') === 'has_not' ? ! $has : $has;

            case 'message_type':
                $is = self::messageTypeMatches($trigger, (string) ($rule['mtype'] ?? ''));

                return ($rule['op'] ?? 'is') === 'is_not' ? ! $is : $is;

            case 'new_contact':
                $new = (bool) $conversation->contact?->created_at?->gt(now()->subDay());

                return ($rule['op'] ?? 'is') === 'is_not' ? ! $new : $new;

            default: // text
                $subject = ($rule['subject'] ?? 'message') === 'var' ? (string) ($vars[$rule['var'] ?? ''] ?? '') : (string) ($trigger?->body ?? '');
                $s = mb_strtolower(trim($subject));
                $value = mb_strtolower(trim((string) ($rule['value'] ?? '')));
                // "yes, haan, ji" → any of these
                $options = array_values(array_filter(array_map('trim', explode(',', $value)), fn ($o) => $o !== ''));
                $number = str_replace([',', ' '], '', $s);

                return match ($rule['op'] ?? 'contains') {
                    'equals' => in_array($s, $options, true),
                    'starts_with' => collect($options)->contains(fn ($o) => str_starts_with($s, $o)),
                    'not_contains' => ! collect($options)->contains(fn ($o) => str_contains($s, $o)),
                    'is_empty' => $s === '',
                    'not_empty' => $s !== '',
                    'number_gt' => is_numeric($number) && (float) $number > (float) $value,
                    'number_lt' => is_numeric($number) && (float) $number < (float) $value,
                    default => collect($options)->contains(fn ($o) => str_contains($s, $o)),
                };
        }
    }

    /**
     * @param  Collection<int, BotFlow>  $flows
     */
    public function pickFlow(Collection $flows, Message $inbound, Conversation $conversation): ?BotFlow
    {
        return $this->resolve($flows, $inbound, $conversation, skipAnyFlows: fn (BotFlow $f) => $this->inCooldown($f, $conversation))[0];
    }

    /**
     * @param  Collection<int, BotFlow>  $flows
     * @param  (callable(BotFlow): bool)|null  $skipAnyFlows  e.g. "is in its cooldown"
     * @return array{0: ?BotFlow, 1: ?string, 2: ?string, 3: bool}  flow, start node, port, tapped a button
     */
    private function resolve(Collection $flows, Message $inbound, Conversation $conversation, ?callable $skipAnyFlows = null): array
    {
        // 1. Menu option tapped
        $payload = (string) ($inbound->content['reply']['id'] ?? $inbound->content['payload'] ?? '');

        if (str_starts_with($payload, BotFlow::PAYLOAD_PREFIX)) {
            $parts = explode(':', substr($payload, strlen(BotFlow::PAYLOAD_PREFIX)));
            $target = $flows->firstWhere('id', (int) ($parts[0] ?? 0));

            if ($target) {
                $node = isset($parts[1], $parts[2]) && $target->isVisual() && BotGraph::node($target->graph, $parts[1]) ? $parts[1] : null;

                return [$target, $node, $node ? $parts[2] : null, true];
            }
        }

        // 2. Any message — every incoming message of any type (highest priority first)
        $any = $flows->first(fn (BotFlow $f) => $f->trigger_type === 'any' && ! ($skipAnyFlows && $skipAnyFlows($f)));

        if ($any) {
            return [$any, null, null, false];
        }

        // 3. Keywords (text, quick-reply button text, interactive reply title)
        $text = (string) ($inbound->body ?? '');

        if ($text !== '') {
            $keyword = $flows->first(fn (BotFlow $f) => $f->trigger_type === 'keyword' && $f->matches($text));

            if ($keyword) {
                return [$keyword, null, null, false];
            }
        }

        // 4. Welcome — the contact's very first message to this number
        $welcome = $flows->firstWhere('trigger_type', 'welcome');

        if ($welcome && $conversation->messages()->where('direction', Message::IN)->count() === 1) {
            return [$welcome, null, null, false];
        }

        // 5. Fallback
        return [$flows->firstWhere('trigger_type', 'fallback'), null, null, false];
    }

    private function inCooldown(BotFlow $flow, Conversation $conversation): bool
    {
        return $flow->cooldown_minutes > 0 && BotFlowRun::where('bot_flow_id', $flow->id)
            ->where('conversation_id', $conversation->id)
            ->where('created_at', '>', now()->subMinutes($flow->cooldown_minutes))
            ->exists();
    }

    /**
     * Runs a flow (from its Trigger, or from a menu option of a visual flow).
     *
     * @return int number of messages sent
     */
    public function run(BotFlow $flow, Conversation $conversation, ?Message $trigger = null, ?string $fromNode = null, ?string $port = null): int
    {
        $this->recordRun($flow, $conversation, $trigger);

        return $flow->isVisual()
            ? $this->runGraph($flow, $conversation, $trigger, $fromNode, $port)
            : $this->runSteps($flow, $conversation);
    }

    private function recordRun(BotFlow $flow, Conversation $conversation, ?Message $trigger): void
    {
        BotFlowRun::create(['bot_flow_id' => $flow->id, 'conversation_id' => $conversation->id, 'trigger_message_id' => $trigger?->id]);
        $flow->forceFill(['runs_count' => $flow->runs_count + 1, 'last_run_at' => now()])->save();
    }

    /**
     * Walks the connections: message → message → … until a Menu (waits for a
     * tap), a Handoff (pauses the bot), a dead end or the safety limit.
     */
    private function runGraph(BotFlow $flow, Conversation $conversation, ?Message $trigger, ?string $fromNode, ?string $port): int
    {
        $graph = $flow->flowGraph();
        $current = $fromNode
            ? BotGraph::next($graph, $fromNode, $port ?? 'out')
            : BotGraph::next($graph, BotGraph::triggerId($graph));

        $budget = (int) config('whatsapp.bot.max_steps', 8);
        $sent = 0;
        $jumps = 0;
        $guard = 0;
        $vars = $this->vars($conversation);

        while ($current !== null && $guard++ < 100) {
            $node = BotGraph::node($graph, $current);

            if (! $node) {
                break;
            }

            $type = $node['type'];
            $data = $node['data'] ?? [];

            if ($type === 'goto') {
                $target = BotFlow::where('whatsapp_account_id', $flow->whatsapp_account_id)->active()->find((int) ($data['flow_id'] ?? 0));

                if (! $target || ++$jumps > self::MAX_JUMPS) {
                    break;
                }

                $this->recordRun($target, $conversation, $trigger);
                $flow = $target;
                $graph = $flow->flowGraph();
                $current = BotGraph::next($graph, BotGraph::triggerId($graph));

                continue;
            }

            if ($type === 'condition') {
                $current = BotGraph::next($graph, $node['id'], $this->evaluate($node, $conversation, $trigger, $vars));

                continue; // sends nothing
            }

            if ($type === 'handoff') {
                $this->handoff($conversation);

                if (filled($data['text'] ?? null) && $budget > 0 && $this->send($flow, $conversation, ['type' => 'text', 'text' => $this->fill((string) $data['text'], $vars)])) {
                    $sent++;
                }

                break;
            }

            if ($budget <= 0 || $type === 'trigger') {
                break;
            }

            if ($type === 'question') {
                if ($this->send($flow, $conversation, ['type' => 'text', 'text' => $this->fill((string) ($data['text'] ?? ''), $vars)])) {
                    $sent++;
                    $this->setWaiting($conversation, ['flow_id' => $flow->id, 'node' => $node['id'], 'attempts' => 0, 'at' => now()->toIso8601String()]);
                }

                break; // wait for the customer's answer
            }

            $step = ['type' => $type] + $data;

            foreach (['text', 'caption', 'footer'] as $field) {
                if (is_string($step[$field] ?? null)) {
                    $step[$field] = $this->fill($step[$field], $vars);
                }
            }

            if ($type === 'menu') {
                $step['options'] = array_map(fn ($o) => $o + [
                    'payload' => $this->optionPayload($flow, $graph, $node['id'], (string) $o['id']),
                ], $data['options'] ?? []);
            }

            if (! $this->send($flow, $conversation, $step)) {
                break;
            }

            $sent++;
            $budget--;

            if ($type === 'menu') {
                break; // wait for the customer's choice
            }

            $current = BotGraph::next($graph, $node['id']);
        }

        return $sent;
    }

    /**
     * Button id for a menu option. Pointing straight at "Go to flow" → open
     * that flow ("bf:{id}"); otherwise continue this flow from the option.
     */
    private function optionPayload(BotFlow $flow, array $graph, string $menuId, string $optionId): string
    {
        $next = BotGraph::node($graph, (string) BotGraph::next($graph, $menuId, $optionId));

        if (($next['type'] ?? null) === 'goto' && ! empty($next['data']['flow_id'])) {
            return BotFlow::PAYLOAD_PREFIX.(int) $next['data']['flow_id'];
        }

        return BotFlow::PAYLOAD_PREFIX.$flow->id.':'.$menuId.':'.$optionId;
    }

    /** Older flows: send the steps one after another. */
    private function runSteps(BotFlow $flow, Conversation $conversation): int
    {
        $sent = 0;
        $steps = array_slice($flow->steps ?? [], 0, (int) config('whatsapp.bot.max_steps', 8));

        foreach ($steps as $step) {
            if (($step['type'] ?? 'text') === 'handoff') {
                $this->handoff($conversation);

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

    private function handoff(Conversation $conversation): void
    {
        $state = $conversation->bot_state ?? [];
        $state['waiting'] = null;

        $conversation->forceFill([
            'bot_paused_until' => now()->addHours((int) config('whatsapp.bot.handoff_hours', 24)),
            'status' => Conversation::STATUS_OPEN,
            'bot_state' => $state,
        ])->save();
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
     * Each option carries its button id in "payload" (visual flows) or "flow_id" (older flows).
     *
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    public function menuPayload(array $step): array
    {
        $options = array_slice($step['options'], 0, 10);
        $id = fn (array $o) => (string) ($o['payload'] ?? BotFlow::PAYLOAD_PREFIX.(int) ($o['flow_id'] ?? 0));
        $body = ['text' => Str::limit((string) ($step['text'] ?? 'Please choose an option'), 1024, '')];
        $footer = filled($step['footer'] ?? null) ? ['footer' => ['text' => Str::limit((string) $step['footer'], 60, '')]] : [];

        if (count($options) <= 3) {
            return ['type' => 'button', 'body' => $body] + $footer + ['action' => ['buttons' => array_map(fn ($o) => [
                'type' => 'reply',
                'reply' => ['id' => $id($o), 'title' => Str::limit((string) $o['title'], 20, '')],
            ], $options)]];
        }

        return ['type' => 'list', 'body' => $body] + $footer + ['action' => [
            'button' => Str::limit((string) ($step['button_label'] ?? '') ?: 'Choose', 20, ''),
            'sections' => [[
                'title' => 'Options',
                'rows' => array_map(fn ($o) => array_filter([
                    'id' => $id($o),
                    'title' => Str::limit((string) $o['title'], 24, ''),
                    'description' => filled($o['description'] ?? null) ? Str::limit((string) $o['description'], 72, '') : null,
                ]), $options),
            ]],
        ]];
    }
}
