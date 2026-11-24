<?php

namespace App\Models;

use App\Support\BotGraph;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An automatic reply flow for one WhatsApp number.
 *
 * Triggers:  any (EVERY incoming message — text, voice, picture, video, file…),
 *            keyword (text matches), welcome (contact's first message),
 *            fallback (nothing else matched), menu (only reachable from a menu button).
 * Content:   visual node graph (BotGraph, `graph` column) — or, for flows made
 *            before the visual builder, a linear list of `steps`.
 */
class BotFlow extends Model
{
    public const TRIGGERS = [
        'any' => 'Any message (every message, any type)',
        'keyword' => 'Keyword',
        'welcome' => 'Welcome (first message)',
        'fallback' => 'Fallback (no other match)',
        'menu' => 'Menu only (opened from a button)',
    ];

    public const MATCHES = ['contains' => 'Contains', 'exact' => 'Exact', 'starts_with' => 'Starts with'];

    public const STEP_TYPES = ['text', 'menu', 'image', 'document', 'handoff'];

    /** Reply-button payload prefix: "bf:{flowId}" (open a flow) or "bf:{flowId}:{nodeId}:{optionId}" (continue a graph). */
    public const PAYLOAD_PREFIX = 'bf:';

    protected $fillable = [
        'whatsapp_account_id', 'name', 'trigger_type', 'match_type', 'keywords',
        'priority', 'cooldown_minutes', 'steps', 'graph', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'graph' => 'array',
            'is_active' => 'boolean',
            'priority' => 'integer',
            'cooldown_minutes' => 'integer',
            'runs_count' => 'integer',
            'last_run_at' => 'datetime',
        ];
    }

    /** True for flows built in the visual (node) builder. */
    public function isVisual(): bool
    {
        return is_array($this->graph) && ! empty($this->graph['nodes']);
    }

    /**
     * The flow as nodes + connections (older step flows are converted on the fly).
     *
     * @return array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>}
     */
    public function flowGraph(): array
    {
        return $this->isVisual() ? $this->graph : BotGraph::fromSteps($this->steps ?? []);
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
