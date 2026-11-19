<?php

namespace App\Policies;

use App\Models\Contact;
use App\Models\User;
use App\Support\Permissions;

class ContactPolicy
{
    public function view(User $user, Contact $contact): bool
    {
        return $user->can(Permissions::CONTACTS_VIEW) && $user->canAccessAccount($contact->whatsapp_account_id);
    }

    public function update(User $user, Contact $contact): bool
    {
        return $user->can(Permissions::CONTACTS_MANAGE) && $user->canAccessAccount($contact->whatsapp_account_id);
    }

    public function delete(User $user, Contact $contact): bool
    {
        return $this->update($user, $contact);
    }
}
