<?php

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Private broadcast channels
|--------------------------------------------------------------------------
| One channel per WhatsApp account. A user may listen only if they are
| active, can view chats, and have access to that account — so real-time
| events can never leak across accounts.
*/

Broadcast::channel('whatsapp.account.{accountId}', function (User $user, int $accountId) {
    return $user->is_active
        && $user->can(Permissions::CHATS_VIEW)
        && $user->canAccessAccount($accountId);
});

Broadcast::channel('App.Models.User.{id}', function (User $user, $id) {
    return (int) $user->id === (int) $id;
});
