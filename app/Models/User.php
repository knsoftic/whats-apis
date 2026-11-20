<?php

namespace App\Models;

use App\Support\Permissions;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'name',
        'company_name',
        'email',
        'phone',
        'password',
        'is_active',
        'plan_id',
        'plan_expires_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'plan_expires_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * WhatsApp accounts explicitly assigned to this user (used for Agents).
     */
    public function whatsappAccounts(): BelongsToMany
    {
        return $this->belongsToMany(WhatsAppAccount::class, 'user_whatsapp_account', 'user_id', 'whatsapp_account_id')
            ->withTimestamps();
    }

    public function plan(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** Accounts whose plan limits are charged to this user. */
    public function ownedAccounts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(WhatsAppAccount::class, 'owner_id');
    }

    /** Active plan, or null when none / expired / disabled. */
    public function activePlan(): ?Plan
    {
        $plan = $this->plan;

        if (! $plan || ! $plan->is_active) {
            return null;
        }

        if ($this->plan_expires_at && $this->plan_expires_at->isPast()) {
            return null;
        }

        return $plan;
    }

    /** Platform staff (Super Admin / Admin) are never limited by plans. */
    public function isPlanLimited(): bool
    {
        return ! $this->canAccessAllAccounts();
    }

    public function canAccessAllAccounts(): bool
    {
        return $this->can(Permissions::ACCOUNTS_ACCESS_ALL);
    }

    /**
     * Query of every WhatsApp account this user may see. Always use this
     * (never WhatsAppAccount::query()) in user-facing code to prevent
     * cross-account data leakage.
     *
     * @return Builder<WhatsAppAccount>
     */
    public function accessibleAccounts(): Builder
    {
        return WhatsAppAccount::query()->accessibleBy($this);
    }

    /**
     * @return array<int, int>
     */
    public function accessibleAccountIds(): array
    {
        return once(fn () => $this->accessibleAccounts()->pluck('id')->all());
    }

    public function canAccessAccount(WhatsAppAccount|int|null $account): bool
    {
        if ($account === null) {
            return false;
        }

        $id = $account instanceof WhatsAppAccount ? $account->id : $account;

        if ($this->canAccessAllAccounts()) {
            return true;
        }

        return $this->whatsappAccounts()->whereKey($id)->exists();
    }

    public function roleName(): string
    {
        return $this->getRoleNames()->first() ?? '—';
    }
}
