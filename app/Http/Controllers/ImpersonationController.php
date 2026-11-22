<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\Impersonation;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Super Admin: "Login as user" to review a customer's account, then return. */
class ImpersonationController extends Controller
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function start(Request $request, User $user): RedirectResponse
    {
        $admin = $request->user();

        abort_unless(Impersonation::canImpersonate($admin, $user), 403, 'You cannot log in as this user.');

        if (Impersonation::active()) {
            return back()->with('error', 'Return to your own account first.');
        }

        $this->logger->log('user.impersonation_started', "Logged in as {$user->email} to review the account", $user, user: $admin);

        Impersonation::$switching = true;
        Auth::guard('web')->login($user);
        Impersonation::$switching = false;
        $request->session()->put(Impersonation::SESSION_KEY, $admin->id);

        return redirect()->route('dashboard')->with('info', "You are now logged in as {$user->name}. Use “Return to admin” at the top to go back.");
    }

    public function leave(Request $request): RedirectResponse
    {
        $adminId = $request->session()->pull(Impersonation::SESSION_KEY);
        $admin = $adminId ? User::find($adminId) : null;
        $viewed = $request->user();

        if (! $admin || ! $admin->is_active || ! $admin->hasRole(Permissions::ROLE_SUPER_ADMIN)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        Impersonation::$switching = true;
        Auth::guard('web')->login($admin);
        Impersonation::$switching = false;

        $this->logger->log('user.impersonation_ended', "Returned from {$viewed?->email}", $viewed, user: $admin);

        return redirect()->route('users.index')->with('success', 'You are back in your own account.');
    }
}
