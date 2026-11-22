<?php

namespace App\Http\Middleware;

use App\Support\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Account status on every request:
 *  - deactivated (is_active = 0) or blocked → signed out;
 *  - suspended → only the "account suspended" notice (and logout) are reachable;
 *  - while the Super Admin is "logged in as" a user these checks are skipped
 *    (so the account can be reviewed), but password / profile changes are blocked.
 */
class EnsureUserIsActive
{
    /** Routes a suspended user may still open. */
    private const SUSPENDED_ALLOWED = ['account.suspended', 'logout', 'home', 'legal.*', 'impersonate.leave'];

    /** Routes the Super Admin may not use while logged in as someone else. */
    private const IMPERSONATION_BLOCKED = ['password.update', 'profile.update', 'password.confirm'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if (Impersonation::active()) {
            if ($request->routeIs(...self::IMPERSONATION_BLOCKED)) {
                return $this->deny($request, 'Not available while you are logged in as this user.', back());
            }

            return $next($request);
        }

        if (! $user->is_active || $user->isBlocked()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $message = $user->isBlocked()
                ? 'This account has been blocked. Please contact support.'
                : 'Your account has been deactivated.';

            return $this->deny($request, $message, redirect()->route('login')->withErrors(['email' => $message]));
        }

        if ($user->isSuspended() && ! $request->routeIs(...self::SUSPENDED_ALLOWED)) {
            return $this->deny($request, 'Your account is suspended.', redirect()->route('account.suspended'));
        }

        return $next($request);
    }

    private function deny(Request $request, string $message, Response $redirect): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 403);
        }

        return $redirect instanceof \Illuminate\Http\RedirectResponse ? $redirect->with('error', $message) : $redirect;
    }
}
