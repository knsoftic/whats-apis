<?php

namespace App\Providers;

use App\Models\User;
use App\Support\CurrentAccount;
use App\Support\Permissions;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One instance per request (safe for queue workers / Octane).
        $this->app->scoped(CurrentAccount::class);
        $this->app->scoped(\App\Support\PlatformSettings::class);
    }

    public function boot(): void
    {
        // Super Admin can do everything.
        Gate::before(function (User $user) {
            return $user->hasRole(Permissions::ROLE_SUPER_ADMIN) ? true : null;
        });

        // HTTPS-only in production.
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        // Catch mass-assignment mistakes during development.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        Password::defaults(function () {
            $rule = Password::min(8);

            return $this->app->isProduction()
                ? $rule->mixedCase()->numbers()->uncompromised()
                : $rule;
        });

        $this->configureRateLimiting();

        // Website settings (name, legal, SMTP) from the Settings page.
        app(\App\Support\PlatformSettings::class)->applyRuntimeConfig();

        // Long-running queue workers: read fresh settings for every job.
        \Illuminate\Support\Facades\Queue::before(fn () => \App\Models\Setting::flushCache());

        // Branding available in every view as $brand.
        View::composer('*', function ($view) {
            $view->with('brand', app(\App\Support\PlatformSettings::class));
        });

        // Account switcher data for the main layout.
        View::composer('layouts.app', function ($view) {
            $current = app(CurrentAccount::class);
            $view->with('switcherAccounts', $current->accounts());
            $view->with('currentAccount', $current->get());
        });
    }

    private function configureRateLimiting(): void
    {
        // Meta webhooks — per WhatsApp account UUID.
        RateLimiter::for('whatsapp-webhook', function (Request $request) {
            $account = $request->route('account');

            // The shared platform URL carries all Embedded Signup numbers → larger budget.
            return $account
                ? Limit::perMinute(config('whatsapp.webhook.rate_limit'))->by('wh:'.$account)
                : Limit::perMinute(config('whatsapp.webhook.rate_limit') * 10)->by('wh:platform');
        });

        // Dashboard message sending — per user.
        RateLimiter::for('whatsapp-send', function (Request $request) {
            return Limit::perMinute(config('whatsapp.send_rate_limit'))
                ->by('send:'.($request->user()?->id ?: $request->ip()));
        });

        // Meta API checks (test connection / sync) — avoid hammering Graph API.
        RateLimiter::for('whatsapp-api-check', function (Request $request) {
            return Limit::perMinute(10)->by('check:'.($request->user()?->id ?: $request->ip()));
        });
    }
}
