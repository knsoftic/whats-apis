<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;
use App\Support\Permissions;

class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return $user->can(Permissions::CHATS_VIEW) && $user->canAccessAccount($conversation->whatsapp_account_id);
    }

    public function send(User $user, Conversation $conversation): bool
    {
        return $user->can(Permissions::CHATS_SEND) && $user->canAccessAccount($conversation->whatsapp_account_id);
    }

    public function sendTemplate(User $user, Conversation $conversation): bool
    {
        return $user->can(Permissions::TEMPLATES_SEND) && $user->canAccessAccount($conversation->whatsapp_account_id);
    }

    public function update(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }
}
