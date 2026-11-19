<?php

namespace App\Support;

use App\Models\User;
use App\Models\WhatsAppAccount;
use Illuminate\Support\Collection;

/**
 * The WhatsApp number the signed-in user is currently working with.
 * Stored in the session; always re-checked against the user's access,
 * so a stale/forged session value can never expose another account.
 */
class CurrentAccount
{
    public const SESSION_KEY = 'current_whatsapp_account_id';

    /** @var Collection<int, WhatsAppAccount>|null */
    private ?Collection $accounts = null;

    private function user(): ?User
    {
        return auth()->user();
    }

    /**
     * Accounts the user may switch to (ordered by name).
     *
     * @return Collection<int, WhatsAppAccount>
     */
    public function accounts(): Collection
    {
        if ($this->accounts !== null) {
            return $this->accounts;
        }

        $user = $this->user();

        return $this->accounts = $user
            ? $user->accessibleAccounts()->orderBy('name')->get()
            : collect();
    }

    public function id(): ?int
    {
        $id = (int) session(self::SESSION_KEY);

        return $id && $this->accounts()->contains('id', $id) ? $id : null;
    }

    public function get(): ?WhatsAppAccount
    {
        $id = $this->id();

        return $id ? $this->accounts()->firstWhere('id', $id) : null;
    }

    /**
     * Current account, or the first accessible active account (used by the chat screen).
     */
    public function getOrFirst(): ?WhatsAppAccount
    {
        return $this->get()
            ?? $this->accounts()->firstWhere('is_active', true)
            ?? $this->accounts()->first();
    }

    public function set(?int $id): bool
    {
        if ($id === null) {
            session()->forget(self::SESSION_KEY);

            return true;
        }

        if (! $this->accounts()->contains('id', $id)) {
            return false;
        }

        session([self::SESSION_KEY => $id]);

        return true;
    }

    /**
     * IDs used to scope dashboard queries: the selected account, or every accessible one.
     *
     * @return array<int, int>
     */
    public function scopeIds(): array
    {
        $id = $this->id();

        return $id ? [$id] : $this->accounts()->pluck('id')->all();
    }
}
