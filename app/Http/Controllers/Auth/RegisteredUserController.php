<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\Permissions;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

/**
 * Public sign-up for businesses. New users get the "Customer" role and the
 * default plan (with its trial period, if any). Super Admin can switch
 * registration off on the Plans page.
 */
class RegisteredUserController extends Controller
{
    public const SETTING = 'platform.registration_enabled';

    public static function enabled(): bool
    {
        return Setting::get(self::SETTING, '1') === '1';
    }

    public function create(): View
    {
        abort_unless(self::enabled(), 404);

        return view('auth.register', ['plan' => Plan::defaultForRegistration()]);
    }

    public function store(Request $request, ActivityLogger $logger): RedirectResponse
    {
        abort_unless(self::enabled(), 404);

        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'company_name' => ['nullable', 'string', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:190', 'unique:'.User::class],
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9 ()\-]{8,20}$/'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'terms' => ['accepted'],
        ], [
            'terms.accepted' => 'Please accept the Terms & Conditions and Privacy Policy.',
            'phone.regex' => 'Enter a valid phone number with country code.',
        ]);

        $plan = Plan::defaultForRegistration();

        $user = DB::transaction(function () use ($data, $plan) {
            $user = User::create([
                'name' => $data['name'],
                'company_name' => $data['company_name'] ?? null,
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'is_active' => true,
                'plan_id' => $plan?->id,
                'plan_expires_at' => $plan?->trial_days ? now()->addDays($plan->trial_days) : null,
            ]);

            \Database\Seeders\RolesAndPermissionsSeeder::ensureRolesExist();
            $user->assignRole(Permissions::ROLE_CUSTOMER);

            return $user;
        });

        event(new Registered($user));
        $logger->log('auth.registered', "New customer registered: {$user->email}", $user, ['plan' => $plan?->name], user: $user);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('success', 'Welcome! Your account is ready'.($plan ? " on the {$plan->name} plan." : '.').' Connect your first WhatsApp number to start.');
    }
}
