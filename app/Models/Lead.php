<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Leads sheet: the first message from every new number, kept for marketing
 * (exportable). Stays even if the contact or chat is deleted later.
 */
class Lead extends Model
{
    protected $fillable = [
        'whatsapp_account_id', 'contact_id', 'wa_id', 'phone', 'profile_name',
        'first_message', 'first_message_type', 'first_message_at',
    ];

    protected function casts(): array
    {
        return ['first_message_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAccount::class, 'whatsapp_account_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Records the lead on the number's first message (no-op afterwards).
     */
    public static function capture(WhatsAppAccount $account, Contact $contact, Message $message, Carbon $at): void
    {
        static::query()->insertOrIgnore([
            'whatsapp_account_id' => $account->id,
            'contact_id' => $contact->id,
            'wa_id' => $contact->wa_id,
            'phone' => $contact->phone ?: '+'.$contact->wa_id,
            'profile_name' => $contact->profile_name ?: $contact->name,
            'first_message' => Str::limit((string) ($message->body ?? $message->previewText()), 500, ''),
            'first_message_type' => $message->type,
            'first_message_at' => $at,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array{account_id?: int|null, from?: string|null, to?: string|null, q?: string|null}  $filters
     */
    public function scopeFiltered(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['account_id'] ?? null, fn ($q, $id) => $q->where('whatsapp_account_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('first_message_at', '>=', Carbon::parse($d)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('first_message_at', '<=', Carbon::parse($d)->endOfDay()))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $digits = preg_replace('/\D/', '', $term);
                $q->where(fn ($w) => $w->where('profile_name', 'like', $like)->orWhere('first_message', 'like', $like)
                    ->when($digits !== '', fn ($x) => $x->orWhere('wa_id', 'like', '%'.$digits.'%')));
            });
    }
}
