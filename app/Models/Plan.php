<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    public const PERIODS = ['free' => 'Free', 'monthly' => 'Monthly', 'yearly' => 'Yearly', 'lifetime' => 'Lifetime'];

    protected $fillable = [
        'name', 'slug', 'description', 'price', 'currency', 'billing_period', 'trial_days',
        'allow_manual', 'allow_embedded_signup', 'max_whatsapp_accounts', 'daily_message_limit',
        'max_contacts', 'allow_bot_flows', 'max_bot_flows', 'is_active', 'is_default', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'trial_days' => 'integer',
            'allow_manual' => 'boolean',
            'allow_embedded_signup' => 'boolean',
            'max_whatsapp_accounts' => 'integer',
            'daily_message_limit' => 'integer',
            'max_contacts' => 'integer',
            'allow_bot_flows' => 'boolean',
            'max_bot_flows' => 'integer',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public static function defaultForRegistration(): ?self
    {
        return static::active()->where('is_default', true)->orderBy('sort_order')->first();
    }

    public function priceLabel(): string
    {
        if ((float) $this->price <= 0) {
            return 'Free';
        }

        $period = match ($this->billing_period) {
            'monthly' => '/month', 'yearly' => '/year', 'lifetime' => ' one-time', default => '',
        };

        return $this->currency.' '.rtrim(rtrim(number_format((float) $this->price, 2), '0'), '.').$period;
    }

    public static function limitLabel(?int $value): string
    {
        return $value === null ? 'Unlimited' : number_format($value);
    }
}
