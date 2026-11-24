<?php

namespace App\Models;

use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    public const OPT_UNKNOWN = 'unknown';
    public const OPT_IN = 'opted_in';
    public const OPT_OUT = 'opted_out';

    protected $fillable = [
        'whatsapp_account_id',
        'wa_id',
        'phone',
        'name',
        'profile_name',
        'email',
        'opt_in_status',
        'opt_in_source',
        'opted_in_at',
        'opted_out_at',
    ];

    protected function casts(): array
    {
        return [
            'opted_in_at' => 'datetime',
            'opted_out_at' => 'datetime',
            'blocked_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAccount::class, 'whatsapp_account_id');
    }

    public function conversation(): HasOne
    {
        return $this->hasOne(Conversation::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(ContactNote::class)->latest();
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $digits = preg_replace('/\D+/', '', $term);

        return $query->where(function (Builder $q) use ($term, $digits) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('profile_name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%");

            if ($digits !== '') {
                $q->orWhere('wa_id', 'like', "%{$digits}%");
            }
        });
    }

    public function displayName(): string
    {
        return $this->name ?: ($this->profile_name ?: $this->phone);
    }

    public function initials(): string
    {
        $name = $this->name ?: $this->profile_name;

        if (! $name) {
            return '#';
        }

        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return mb_strtoupper(mb_substr($parts[0] ?? '', 0, 1).mb_substr($parts[1] ?? '', 0, 1));
    }

    /**
     * Normalise any phone input to WhatsApp ID format (digits only, with country code).
     */
    public static function normalizeWaId(string $phone): string
    {
        return ltrim((string) preg_replace('/\D+/', '', $phone), '0');
    }

    public static function formatPhone(string $waId): string
    {
        return '+'.self::normalizeWaId($waId);
    }
}
