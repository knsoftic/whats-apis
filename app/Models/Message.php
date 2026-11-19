<?php

namespace App\Models;

use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    public const IN = 'inbound';
    public const OUT = 'outbound';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_READ = 'read';
    public const STATUS_FAILED = 'failed';
    public const STATUS_RECEIVED = 'received';

    /** Media types that carry an attachment. */
    public const MEDIA_TYPES = ['image', 'video', 'audio', 'document', 'sticker'];

    protected $fillable = [
        'whatsapp_account_id',
        'conversation_id',
        'contact_id',
        'wa_message_id',
        'direction',
        'type',
        'sender',
        'recipient',
        'body',
        'content',
        'context_wa_message_id',
        'reply_to_message_id',
        'status',
        'error_code',
        'error_message',
        'api_response',
        'sent_by_user_id',
        'wa_timestamp',
        'sent_at',
        'delivered_at',
        'read_at',
        'failed_at',
    ];

    protected $hidden = [
        'api_response',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'api_response' => 'array',
            'wa_timestamp' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAccount::class, 'whatsapp_account_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'reply_to_message_id');
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    public function isInbound(): bool
    {
        return $this->direction === self::IN;
    }

    public function isOutbound(): bool
    {
        return $this->direction === self::OUT;
    }

    /**
     * Status order — a webhook can never move a message "backwards"
     * (e.g. a late "delivered" after "read"). "failed" always wins.
     */
    public static function statusRank(string $status): int
    {
        return match ($status) {
            self::STATUS_PENDING => 0,
            self::STATUS_SENT => 1,
            self::STATUS_DELIVERED => 2,
            self::STATUS_READ => 3,
            self::STATUS_FAILED => 9,
            default => 0,
        };
    }

    /**
     * Short text used in the conversation list.
     */
    public function previewText(): string
    {
        $text = match ($this->type) {
            'text' => $this->body,
            'image' => '📷 '.($this->body ?: 'Photo'),
            'video' => '🎥 '.($this->body ?: 'Video'),
            'audio' => ($this->content['voice'] ?? false) ? '🎤 Voice message' : '🎵 Audio',
            'document' => '📄 '.($this->body ?: ($this->content['filename'] ?? 'Document')),
            'sticker' => '💟 Sticker',
            'location' => '📍 '.($this->content['name'] ?? 'Location'),
            'contacts' => '👤 Contact card',
            'interactive', 'button' => '🔘 '.($this->body ?: 'Button reply'),
            'reaction' => ($this->content['emoji'] ?? '👍').' Reaction',
            'template' => '📋 '.($this->body ?: 'Template: '.($this->content['name'] ?? '')),
            default => '⚠️ Unsupported message',
        };

        return Str::limit((string) $text, 120);
    }
}
