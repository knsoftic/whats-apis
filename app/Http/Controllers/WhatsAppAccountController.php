<?php

namespace App\Http\Controllers;

use App\Actions\WhatsApp\CheckAccountConnection;
use App\Exceptions\PlanLimitException;
use App\Services\PlanGate;
use App\Http\Requests\WhatsAppAccountRequest;
use App\Models\WhatsAppAccount;
use App\Services\ActivityLogger;
use App\Services\WhatsApp\WhatsAppApiException;
use App\Services\WhatsApp\WhatsAppCloudApiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class WhatsAppAccountController extends Controller
{
    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly CheckAccountConnection $checker,
        private readonly PlanGate $planGate,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', WhatsAppAccount::class);

        $accounts = $request->user()->accessibleAccounts()
            ->withCount([
                'conversations',
                'conversations as unread_conversations_count' => fn ($q) => $q->where('unread_count', '>', 0),
            ])
            ->orderBy('name')
            ->get();

        $limited = $request->user()->isPlanLimited();
        $plan = $limited ? $request->user()->activePlan() : null;

        // Customers: numbers used vs. plan limit (they can't delete, so a full plan means no more adding).
        $used = $limited ? $request->user()->ownedAccounts()->count() : null;
        $max = $plan?->max_whatsapp_accounts;
        $atLimit = $limited && $max !== null && $used >= $max;

        return view('accounts.index', [
            'accounts' => $accounts,
            'canConnect' => ! $atLimit && (! $limited || ($plan?->allow_embedded_signup ?? false)),
            'canManual' => ! $atLimit && (! $limited || ($plan?->allow_manual ?? false)),
            'numberLimit' => $limited ? ['used' => $used, 'max' => $max, 'full' => $atLimit, 'plan' => $plan?->name] : null,
        ]);
    }

    public function create(Request $request): View|RedirectResponse
    {
        $this->authorize('create', WhatsAppAccount::class);

        try {
            $this->planGate->ensureCanAddAccount($request->user(), WhatsAppAccount::ONBOARD_MANUAL);
        } catch (PlanLimitException $e) {
            return redirect()->route('accounts.index')->with('error', $e->getMessage());
        }

        return view('accounts.form', [
            'owners' => $this->ownerOptions($request),
            'account' => new WhatsAppAccount([
                'graph_api_version' => config('whatsapp.default_graph_version'),
                'is_active' => true,
            ]),
            'suggestedVerifyToken' => Str::random(40),
            // Known before saving → the Callback URL can be copied into Meta right away.
            'suggestedUuid' => (string) Str::uuid(),
        ]);
    }

    public function store(WhatsAppAccountRequest $request): RedirectResponse
    {
        $user = $request->user();
        $this->planGate->ensureCanAddAccount($user, WhatsAppAccount::ONBOARD_MANUAL);

        $data = $request->validated();
        $ownerId = $data['owner_id'] ?? null;
        $uuid = $data['uuid'] ?? null;
        unset($data['owner_id'], $data['uuid']);

        $account = WhatsAppAccount::make($data + ['created_by' => $user->id]);
        $account->uuid = $uuid ?: (string) Str::uuid(); // same id as the Callback URL shown on the form
        $account->save();
        $this->assignOwner($account, $user->isPlanLimited() ? $user->id : $ownerId);

        $this->logger->log('account.created', "Added WhatsApp account {$account->name}", $account, [
            'waba_id' => $account->waba_id,
            'phone_number_id' => $account->phone_number_id,
        ], $account->id);

        $result = $this->checker->run($account);

        return redirect()->route('accounts.show', $account)->with(
            $result->ok ? 'success' : 'warning',
            $result->ok
                ? 'Account saved and API access confirmed. Next: configure the webhook in Meta.'
                : 'Account saved, but the API check failed: '.$result->message,
        );
    }

    public function show(WhatsAppAccount $account): View
    {
        $this->authorize('view', $account);

        $account->loadCount(['contacts', 'conversations', 'messages', 'templates']);

        return view('accounts.show', [
            'account' => $account,
            'recentEvents' => $account->webhookEvents()->latest('id')->limit(10)->get(['id', 'event_type', 'status', 'error', 'created_at']),
        ]);
    }

    public function edit(WhatsAppAccount $account): View
    {
        $this->authorize('update', $account);

        return view('accounts.form', [
            'account' => $account,
            'owners' => $this->ownerOptions(request()),
            'suggestedVerifyToken' => Str::random(40),
        ]);
    }

    /**
     * Customers (plan users) an admin can make owner of a number.
     */
    private function ownerOptions(Request $request): \Illuminate\Support\Collection
    {
        if ($request->user()->isPlanLimited()) {
            return collect();
        }

        return \App\Models\User::role(\App\Support\Permissions::ROLE_CUSTOMER)->orderBy('name')->get(['id', 'name', 'email', 'company_name']);
    }

    /**
     * The owner's plan limits apply to the number, and the owner can see it.
     */
    private function assignOwner(WhatsAppAccount $account, ?int $ownerId): void
    {
        $account->forceFill(['owner_id' => $ownerId])->save();

        if ($ownerId) {
            $account->users()->syncWithoutDetaching([$ownerId]);
        }
    }

    public function update(WhatsAppAccountRequest $request, WhatsAppAccount $account): RedirectResponse
    {
        $data = $request->validated();
        $ownerChange = array_key_exists('owner_id', $data) && ! $request->user()->isPlanLimited();
        $newOwner = $data['owner_id'] ?? null;
        unset($data['owner_id']);

        if ($ownerChange && (int) $newOwner !== (int) $account->owner_id) {
            $this->assignOwner($account, $newOwner ? (int) $newOwner : null);
        }

        // Blank secret fields mean "keep the current value".
        foreach (['access_token', 'app_secret', 'webhook_verify_token'] as $secret) {
            if (empty($data[$secret])) {
                unset($data[$secret]);
            }
        }

        $account->fill($data);

        $credentialsChanged = $account->isDirty(['access_token', 'app_secret', 'waba_id', 'phone_number_id', 'graph_api_version']);
        $verifyTokenChanged = $account->isDirty('webhook_verify_token');
        $changed = array_keys($account->getDirty());

        if ($credentialsChanged) {
            $account->forceFill(['api_status' => WhatsAppAccount::API_UNKNOWN, 'subscription_status' => WhatsAppAccount::SUB_UNKNOWN]);
        }

        if ($verifyTokenChanged || $account->isDirty('app_secret')) {
            // Meta must verify the webhook again with the new token / secret.
            $account->forceFill(['webhook_status' => WhatsAppAccount::WEBHOOK_NOT_VERIFIED, 'webhook_verified_at' => null]);
        }

        $account->save();

        $this->logger->log('account.updated', "Updated WhatsApp account {$account->name}", $account, [
            'changed_fields' => $changed,
        ], $account->id);

        if ($credentialsChanged) {
            $result = $this->checker->run($account);

            return redirect()->route('accounts.show', $account)->with(
                $result->ok ? 'success' : 'warning',
                $result->ok ? 'Saved. API access confirmed.' : 'Saved, but the API check failed: '.$result->message,
            );
        }

        return redirect()->route('accounts.show', $account)->with('success', 'Account updated.'.($verifyTokenChanged ? ' Re-verify the webhook in Meta with the new verify token.' : ''));
    }

    public function destroy(Request $request, WhatsAppAccount $account): RedirectResponse
    {
        $this->authorize('delete', $account);

        $request->validate([
            'confirm_name' => ['required', 'string', function ($attr, $value, $fail) use ($account) {
                if (trim($value) !== $account->name) {
                    $fail('Type the exact account name to confirm deletion.');
                }
            }],
        ]);

        $name = $account->name;
        $phoneId = $account->phone_number_id;
        $account->delete();

        $this->logger->log('account.deleted', "Deleted WhatsApp account {$name} and all its data", properties: ['phone_number_id' => $phoneId]);

        return redirect()->route('accounts.index')->with('success', "WhatsApp account “{$name}” and its conversations were deleted.");
    }

    public function test(WhatsAppAccount $account): RedirectResponse
    {
        $this->authorize('update', $account);

        $result = $this->checker->run($account);

        return back()->with(
            $result->ok ? 'success' : 'error',
            $result->ok ? $result->message : 'API check failed: '.$result->message,
        );
    }

    public function subscribe(WhatsAppAccount $account): RedirectResponse
    {
        $this->authorize('update', $account);

        try {
            $ok = WhatsAppCloudApiService::for($account)->subscribeApp();
        } catch (WhatsAppApiException $e) {
            $account->forceFill([
                'subscription_status' => WhatsAppAccount::SUB_FAILED,
                'subscription_status_message' => $e->userMessage(),
                'subscription_checked_at' => now(),
            ])->save();

            return back()->with('error', 'Could not subscribe the app: '.$e->userMessage());
        }

        $account->forceFill([
            'subscription_status' => $ok ? WhatsAppAccount::SUB_SUBSCRIBED : WhatsAppAccount::SUB_NOT_SUBSCRIBED,
            'subscription_status_message' => $ok ? 'App subscribed to this WABA via API.' : 'Meta did not confirm the subscription.',
            'subscription_checked_at' => now(),
        ])->save();

        $this->logger->log('account.subscribed', "Subscribed app to WABA for {$account->name}", $account, ['ok' => $ok], $account->id);

        return back()->with($ok ? 'success' : 'warning', $ok ? 'App subscribed to WABA webhooks.' : 'Meta did not confirm the subscription.');
    }

    public function toggle(WhatsAppAccount $account): RedirectResponse
    {
        $this->authorize('update', $account);

        $account->forceFill(['is_active' => ! $account->is_active])->save();

        $this->logger->log(
            $account->is_active ? 'account.enabled' : 'account.disabled',
            ($account->is_active ? 'Enabled' : 'Disabled')." WhatsApp account {$account->name}",
            $account,
            accountId: $account->id,
        );

        return back()->with('success', $account->is_active
            ? 'Account enabled.'
            : 'Account disabled. Incoming webhooks are still stored, but sending is blocked.');
    }

    /**
     * Returns the webhook verify token so an admin can paste it into Meta.
     * (The access token and App Secret are never revealed.)
     */
    public function verifyToken(WhatsAppAccount $account)
    {
        $this->authorize('update', $account);

        $this->logger->log('account.verify_token_viewed', "Viewed webhook verify token of {$account->name}", $account, accountId: $account->id);

        return response()->json(['verify_token' => $account->webhook_verify_token])
            ->header('Cache-Control', 'no-store');
    }
}
