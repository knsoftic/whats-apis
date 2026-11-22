<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Support\Permissions;

class WhatsAppAccountPolicy
{
    /** Account management screens. */
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::ACCOUNTS_MANAGE);
    }

    public function view(User $user, WhatsAppAccount $account): bool
    {
        return $user->can(Permissions::ACCOUNTS_MANAGE) && $user->canAccessAccount($account);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::ACCOUNTS_MANAGE);
    }

    public function update(User $user, WhatsAppAccount $account): bool
    {
        return $this->view($user, $account);
    }

    /**
     * Only staff (Super Admin / Admin) can remove a number. Customers keep what
     * they connected — otherwise they could delete and re-add to get around
     * their plan's number limit.
     */
    public function delete(User $user, WhatsAppAccount $account): bool
    {
        return ! $user->isPlanLimited() && $this->view($user, $account);
    }

    /** Changing which number / WABA an account points to: staff only (same reason as delete). */
    public function changeNumber(User $user, WhatsAppAccount $account): bool
    {
        return ! $user->isPlanLimited() && $this->view($user, $account);
    }

    /** Working inside the account (chats, contacts, templates). */
    public function use(User $user, WhatsAppAccount $account): bool
    {
        return $user->canAccessAccount($account);
    }
}
