<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;

/**
 * Auto-discovered by Laravel (each handle* method listens to its type-hinted event).
 */
class LogAuthenticationActivity
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function handleLogin(Login $event): void
    {
        // "Login as user" / return to admin is logged by ImpersonationController, not as a real sign-in.
        if (\App\Support\Impersonation::$switching) {
            return;
        }

        if ($event->user instanceof User) {
            $event->user->forceFill([
                'last_login_at' => now(),
                'last_login_ip' => request()->ip(),
            ])->saveQuietly();

            $this->logger->log('auth.login', 'Logged in', $event->user, user: $event->user);
        }
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->user instanceof User) {
            $this->logger->log('auth.logout', 'Logged out', $event->user, user: $event->user);
        }
    }

    public function handleFailed(Failed $event): void
    {
        $this->logger->log('auth.failed', 'Failed login attempt', properties: [
            'email' => $event->credentials['email'] ?? null,
        ]);
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        if ($event->user instanceof User) {
            $this->logger->log('auth.password_reset', 'Password was reset', $event->user, user: $event->user);
        }
    }
}
