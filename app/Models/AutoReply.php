<?php

namespace App\Models;

use App\Support\BotGraph;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An Auto Reply rule ("always reply / auto reply / quick action buttons").
 *
 * Decided inside this app by reading the chat history — is this the customer's
 * first message, are they coming back after a quiet time, does it match a keyword —
 * and completely separate from Bot Flows (own rules, own engine, own button ids "ar:").
 */
class AutoReply extends Model
{
    public const TRIGGERS = [
        'first_message' => 'First message (new customer)',
        'returning' => 'Returning customer (wrote before)',
        'keyword' => 'Keyword',
        'always' => 'Every message (always reply)',
    ];

    public const HOURS_MODES = [
        'always' => 'Any time',
        'inside' => 'Only during these hours',
        'outside' => 'Only outside these hours (away message)',
    ];

    /** Quick action button ids: "ar:{ruleId}:{buttonId}". */
    public const PAYLOAD_PREFIX = 'ar:';

    public const MAX_BUTTONS = 3;

    protected $fillable = [
        'whatsapp_account_id', 'name', 'trigger', 'match_type', 'keywords', 'gap_hours', 'message_types',
        'while_chatting', 'hours_mode', 'days', 'from_time', 'to_time', 'cooldown_minutes',
        'reply_text', 'buttons', 'priority', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'message_types' => 'array',
            'days' => 'array',
            'buttons' => 'array',
            'while_chatting' => 'boolean',
            'is_active' => 'boolean',
            'gap_hours' => 'integer',
            'cooldown_minutes' => 'integer',
            'priority' => 'integer',
            'runs_count' => 'integer',
            'last_run_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAccount::class, 'whatsapp_account_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** @return list<string> */
    public function keywordList(): array
    {
        return collect(explode(',', (string) $this->keywords))->map(fn ($k) => mb_strtolower(trim($k)))->filter()->values()->all();
    }

    public function matchesKeyword(string $text): bool
    {
        $text = mb_strtolower(trim($text));

        if ($text === '') {
            return false;
        }

        foreach ($this->keywordList() as $keyword) {
            $hit = match ($this->match_type) {
                'exact' => $text === $keyword,
                'starts_with' => str_starts_with($text, $keyword),
                default => str_contains($text, $keyword),
            };

            if ($hit) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{id: string, title: string, reply: string}> */
    public function buttonList(): array
    {
        return array_values(array_filter($this->buttons ?? [], fn ($b) => filled($b['title'] ?? null)));
    }

    /** One line for the list page, e.g. "Keyword (contains): price · voice notes · outside 09:00–18:00". */
    public function summary(): string
    {
        $parts = [match ($this->trigger) {
            'keyword' => 'Keyword ('.strtolower(BotFlow::MATCHES[$this->match_type] ?? 'contains').'): '.$this->keywords,
            'returning' => $this->gap_hours > 0 ? "Returning customer after {$this->gap_hours} h quiet" : 'Every message of a returning customer',
            default => self::TRIGGERS[$this->trigger] ?? $this->trigger,
        }];

        if ($this->message_types) {
            $parts[] = collect($this->message_types)->map(fn ($t) => strtolower(BotGraph::MESSAGE_TYPES[$t] ?? $t))->implode(', ');
        }
        if ($this->hours_mode !== 'always') {
            $parts[] = ($this->hours_mode === 'inside' ? 'during ' : 'outside ').$this->from_time.'–'.$this->to_time;
        }
        if ($this->while_chatting) {
            $parts[] = 'also during live chats';
        }
        if ($this->cooldown_minutes > 0) {
            $parts[] = "once per {$this->cooldown_minutes} min";
        }

        return implode(' · ', $parts);
    }
}
