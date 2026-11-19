<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookEvent extends Model
{
    use MassPrunable;

    /**
     * Raw payloads of processed events are deleted after the retention period
     * (`php artisan model:prune`, scheduled daily). Failed events are kept.
     */
    public function prunable(): Builder
    {
        $days = (int) config('whatsapp.webhook.retention_days');

        return $days > 0
            ? static::where('status', self::PROCESSED)->where('created_at', '<', now()->subDays($days))
            : static::whereRaw('1 = 0');
    }

    public const RECEIVED = 'received';
    public const PROCESSING = 'processing';
    public const PROCESSED = 'processed';
    public const FAILED = 'failed';

    protected $fillable = [
        'whatsapp_account_id',
        'payload_hash',
        'event_type',
        'payload',
        'status',
        'attempts',
        'error',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAccount::class, 'whatsapp_account_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function decodedPayload(): array
    {
        return json_decode($this->payload, true) ?: [];
    }
}
