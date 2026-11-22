<?php

namespace App\Support;

use App\Models\User;

/**
 * "Login as user": the Super Admin signs in as a customer / agent to review
 * their account. The admin's id is kept in the session so they can return.
 */
class Impersonation
{
    public const SESSION_KEY = 'impersonator_id';

    /** True while switching users, so the switch is not recorded as the user's own login. */
    public static bool $switching = false;

    public static function active(): bool
    {
        return app()->bound('session') && session()->has(self::SESSION_KEY);
    }

    public static function impersonator(): ?User
    {
        $id = self::active() ? session(self::SESSION_KEY) : null;

        return $id ? User::find($id) : null;
    }

    /** Who may log in as whom: Super Admin → any other non–Super Admin user. */
    public static function canImpersonate(User $admin, User $target): bool
    {
        return $admin->hasRole(Permissions::ROLE_SUPER_ADMIN)
            && ! $admin->is($target)
            && ! $target->hasRole(Permissions::ROLE_SUPER_ADMIN);
    }
}
