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

    public function delete(User $user, WhatsAppAccount $account): bool
    {
        return $this->view($user, $account);
    }

    /** Working inside the account (chats, contacts, templates). */
    public function use(User $user, WhatsAppAccount $account): bool
    {
        return $user->canAccessAccount($account);
    }
}
