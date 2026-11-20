<?php

namespace App\Models;

use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    /** Meta's customer service window, in hours. */
    public const SERVICE_WINDOW_HOURS = 24;

    protected $fillable = [
        'whatsapp_account_id',
        'contact_id',
        'status',
        'unread_count',
        'last_message_preview',
        'last_message_direction',
        'last_message_at',
        'last_inbound_at',
        'assigned_user_id',
        'bot_paused_until',
        'bot_state',
    ];

    protected function casts(): array
    {
        return [
            'unread_count' => 'integer',
            'last_message_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'bot_paused_until' => 'datetime',
            'bot_state' => 'array',
        ];
    }

    public function isBotPaused(): bool
    {
        return $this->bot_paused_until !== null && $this->bot_paused_until->isFuture();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAccount::class, 'whatsapp_account_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * Free-form messages are allowed only within 24h of the customer's last message.
     */
    public function isWithinServiceWindow(): bool
    {
        $expires = $this->serviceWindowExpiresAt();

        return $expires !== null && $expires->isFuture();
    }

    public function serviceWindowExpiresAt(): ?Carbon
    {
        return $this->last_inbound_at?->copy()->addHours(self::SERVICE_WINDOW_HOURS);
    }
}
