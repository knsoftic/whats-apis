<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Auth\RegisteredUserController;
use App\Models\Plan;
use App\Models\Setting;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Plans (Super Admin): what each customer may do — Connect with Meta,
 * manual numbers, number/contact/bot limits, daily message limit.
 */
class PlanController extends Controller
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function index(): View
    {
        return view('plans.index', [
            'plans' => Plan::withCount('users')->orderBy('sort_order')->orderBy('id')->get(),
            'registrationEnabled' => RegisteredUserController::enabled(),
        ]);
    }

    public function create(): View
    {
        return view('plans.form', ['plan' => new Plan([
            'currency' => app(\App\Support\PlatformSettings::class)->currency(), 'billing_period' => 'monthly', 'is_active' => true,
            'allow_manual' => true, 'allow_embedded_signup' => true, 'allow_bot_flows' => true,
        ])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $plan = DB::transaction(function () use ($request) {
            $data = $this->validated($request);
            $plan = Plan::create($data);
            $this->keepSingleDefault($plan);

            return $plan;
        });

        $this->logger->log('plan.created', "Created plan {$plan->name}", $plan);

        return redirect()->route('plans.index')->with('success', "Plan “{$plan->name}” created.");
    }

    public function edit(Plan $plan): View
    {
        return view('plans.form', compact('plan'));
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        DB::transaction(function () use ($request, $plan) {
            $plan->update($this->validated($request, $plan));
            $this->keepSingleDefault($plan);
        });

        $this->logger->log('plan.updated', "Updated plan {$plan->name}", $plan);

        return redirect()->route('plans.index')->with('success', "Plan “{$plan->name}” updated.");
    }

    public function destroy(Plan $plan): RedirectResponse
    {
        if ($plan->users()->exists()) {
            return back()->with('error', 'This plan has users. Move them to another plan or deactivate the plan instead.');
        }

        $name = $plan->name;
        $plan->delete();
        $this->logger->log('plan.deleted', "Deleted plan {$name}");

        return redirect()->route('plans.index')->with('success', "Plan “{$name}” deleted.");
    }

    public function settings(Request $request): RedirectResponse
    {
        $enabled = $request->boolean('registration_enabled');
        Setting::put(RegisteredUserController::SETTING, $enabled ? '1' : '0');
        $this->logger->log('settings.registration', 'Public registration '.($enabled ? 'enabled' : 'disabled'));

        return back()->with('success', 'Public registration is now '.($enabled ? 'ON' : 'OFF').'.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Plan $plan = null): array
    {
        foreach (['max_whatsapp_accounts', 'daily_message_limit', 'max_contacts', 'max_bot_flows', 'trial_days'] as $field) {
            if ($request->input($field) === '') {
                $request->merge([$field => null]);
            }
        }

        $request->merge([
            'slug' => Str::slug($request->input('slug') ?: $request->input('name')),
            'allow_manual' => $request->boolean('allow_manual'),
            'allow_embedded_signup' => $request->boolean('allow_embedded_signup'),
            'allow_bot_flows' => $request->boolean('allow_bot_flows'),
            'is_active' => $request->boolean('is_active'),
            'is_default' => $request->boolean('is_default'),
        ]);

        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'slug' => ['required', 'alpha_dash', 'max:80', Rule::unique('plans', 'slug')->ignore($plan?->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'currency' => ['required', 'string', 'size:3'],
            'billing_period' => ['required', Rule::in(array_keys(Plan::PERIODS))],
            'trial_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'allow_manual' => ['boolean'],
            'allow_embedded_signup' => ['boolean'],
            'max_whatsapp_accounts' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'daily_message_limit' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'max_contacts' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'allow_bot_flows' => ['boolean'],
            'max_bot_flows' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]) + ['sort_order' => 0];
    }

    private function keepSingleDefault(Plan $plan): void
    {
        if ($plan->is_default) {
            Plan::whereKeyNot($plan->id)->where('is_default', true)->update(['is_default' => false]);
        }
    }
}
