<?php

namespace App\Models;

use Database\Factories\WhatsAppAccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class WhatsAppAccount extends Model
{
    /** @use HasFactory<WhatsAppAccountFactory> */
    use HasFactory;

    public const API_UNKNOWN = 'unknown';
    public const API_CONNECTED = 'connected';
    public const API_FAILED = 'failed';

    public const WEBHOOK_NOT_VERIFIED = 'not_verified';
    public const WEBHOOK_VERIFIED = 'verified';

    public const SUB_UNKNOWN = 'unknown';
    public const SUB_SUBSCRIBED = 'subscribed';
    public const SUB_NOT_SUBSCRIBED = 'not_subscribed';
    public const SUB_FAILED = 'failed';

    protected $table = 'whatsapp_accounts';

    protected $fillable = [
        'name',
        'meta_business_id',
        'waba_id',
        'phone_number_id',
        'graph_api_version',
        'access_token',
        'webhook_verify_token',
        'app_secret',
        'is_active',
        'created_by',
    ];

    public const ONBOARD_MANUAL = 'manual';
    public const ONBOARD_EMBEDDED = 'embedded_signup';

    /**
     * Secrets never leave the server (JSON / arrays / broadcasting).
     */
    protected $hidden = [
        'access_token',
        'webhook_verify_token',
        'app_secret',
        'phone_meta',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'webhook_verify_token' => 'encrypted',
            'app_secret' => 'encrypted',
            'phone_meta' => 'array',
            'is_active' => 'boolean',
            'api_checked_at' => 'datetime',
            'webhook_verified_at' => 'datetime',
            'last_webhook_at' => 'datetime',
            'subscription_checked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (WhatsAppAccount $account) {
            $account->uuid ??= (string) Str::uuid();
            $account->graph_api_version ??= config('whatsapp.default_graph_version');
        });
    }

    // ---------------------------------------------------------------- relations

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_whatsapp_account', 'whatsapp_account_id', 'user_id')
            ->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'whatsapp_account_id');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'whatsapp_account_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'whatsapp_account_id');
    }

    public function templates(): HasMany
    {
        return $this->hasMany(MessageTemplate::class, 'whatsapp_account_id');
    }

    public function webhookEvents(): HasMany
    {
        return $this->hasMany(WebhookEvent::class, 'whatsapp_account_id');
    }

    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class, 'whatsapp_account_id');
    }

    // ------------------------------------------------------------------ scopes

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Restrict to accounts the given user may access.
     */
    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        if ($user->canAccessAllAccounts()) {
            return $query;
        }

        return $query->whereHas('users', fn (Builder $q) => $q->whereKey($user->id));
    }

    // ----------------------------------------------------------------- helpers

    /**
     * Token shown in the UI — never the full value.
     */
    public function maskedAccessToken(): string
    {
        return self::mask((string) $this->access_token);
    }

    public function maskedAppSecret(): string
    {
        return self::mask((string) $this->app_secret);
    }

    public static function mask(string $secret): string
    {
        $length = strlen($secret);

        if ($length === 0) {
            return '—';
        }

        if ($length <= 10) {
            return str_repeat('•', 8);
        }

        return substr($secret, 0, 4).str_repeat('•', 12).substr($secret, -4);
    }

    public function webhookUrl(): string
    {
        return route('webhooks.whatsapp.verify', ['account' => $this->uuid]);
    }

    public function graphBaseUrl(): string
    {
        return rtrim(config('whatsapp.graph_url'), '/').'/'.$this->graph_api_version;
    }

    public function isApiConnected(): bool
    {
        return $this->api_status === self::API_CONNECTED;
    }

    public function isWebhookVerified(): bool
    {
        return $this->webhook_status === self::WEBHOOK_VERIFIED;
    }

    public function isSubscribed(): bool
    {
        return $this->subscription_status === self::SUB_SUBSCRIBED;
    }

    /**
     * Only an enabled account with confirmed API access and a verified webhook
     * counts as fully operational.
     */
    public function isOperational(): bool
    {
        return $this->is_active && $this->isApiConnected() && $this->isWebhookVerified();
    }

    public function isEmbeddedSignup(): bool
    {
        return $this->onboarding_method === self::ONBOARD_EMBEDDED;
    }

    public function label(): string
    {
        return $this->display_phone_number
            ? "{$this->name} ({$this->display_phone_number})"
            : $this->name;
    }
}
