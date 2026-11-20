<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An automatic reply flow for one WhatsApp number.
 *
 * Triggers:  keyword (text matches), welcome (contact's first message),
 *            fallback (nothing else matched), menu (only reachable from a menu button).
 * Steps:     text · menu (reply buttons / list → other flows) · image · document · handoff.
 */
class BotFlow extends Model
{
    public const TRIGGERS = [
        'keyword' => 'Keyword',
        'welcome' => 'Welcome (first message)',
        'fallback' => 'Fallback (no other match)',
        'menu' => 'Menu only (opened from a button)',
    ];

    public const MATCHES = ['contains' => 'Contains', 'exact' => 'Exact', 'starts_with' => 'Starts with'];

    public const STEP_TYPES = ['text', 'menu', 'image', 'document', 'handoff'];

    /** Reply-button payload prefix: "bf:{flowId}". */
    public const PAYLOAD_PREFIX = 'bf:';

    protected $fillable = [
        'whatsapp_account_id', 'name', 'trigger_type', 'match_type', 'keywords',
        'priority', 'cooldown_minutes', 'steps', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'is_active' => 'boolean',
            'priority' => 'integer',
            'cooldown_minutes' => 'integer',
            'runs_count' => 'integer',
            'last_run_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAccount::class, 'whatsapp_account_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(BotFlowRun::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @return array<int, string>
     */
    public function keywordList(): array
    {
        return collect(explode(',', (string) $this->keywords))
            ->map(fn ($k) => mb_strtolower(trim($k)))
            ->filter()
            ->values()
            ->all();
    }

    public function matches(string $text): bool
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
}
