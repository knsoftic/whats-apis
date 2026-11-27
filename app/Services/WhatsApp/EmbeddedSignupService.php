<?php

namespace App\Services\WhatsApp;

use App\Actions\WhatsApp\CheckAccountConnection;
use App\Exceptions\EmbeddedSignupException;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Services\ActivityLogger;
use App\Support\MetaPlatform;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Completes WhatsApp Embedded Signup (v4) for a Tech Provider:
 *   1. exchange the 5-minute code for a business token (server-to-server)
 *   2. confirm which WABAs the customer granted (debug_token)
 *   3. subscribe our app to the customer's WABA webhooks
 *   4. register the phone number on Cloud API (6-digit PIN)
 *   5. save the number as a WhatsApp account and run the normal API check
 */
class EmbeddedSignupService
{
    public const EVENT_FINISH = 'FINISH';
    public const EVENT_APP_ONBOARDING = 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING';

    public function __construct(
        private readonly MetaPlatform $platform,
        private readonly CheckAccountConnection $checker,
        private readonly ActivityLogger $logger,
        private readonly \App\Services\PlanGate $planGate,
    ) {}

    /**
     * @param  array{code:string, event?:?string, waba_id?:?string, phone_number_id?:?string, business_id?:?string, name?:?string, pin?:?string}  $input
     * @return array{account: WhatsAppAccount, created: bool, warnings: array<int, string>}
     */
    public function complete(array $input, User $user): array
    {
        if (! $this->platform->isConfigured()) {
            throw new EmbeddedSignupException('Embedded Signup is not configured. A Super Admin must fill Settings → Meta Tech Provider.');
        }

        $warnings = [];
        $token = $this->exchangeCode($input['code']);

        // Which WABAs did the customer actually share with our app?
        $granted = $this->grantedWabaIds($token);
        $wabaId = $input['waba_id'] ?? null;

        if ($wabaId && $granted !== [] && ! in_array((string) $wabaId, $granted, true)) {
            throw new EmbeddedSignupException('The WhatsApp Business Account returned by the popup was not shared with this app. Please run “Connect with Meta” again.');
        }

        // The popup normally tells us which WABA was picked. Without it, never guess between
        // several shared WABAs (the first one may be an old / banned account): use the only
        // one that is not connected here yet, else ask to run the signup again.
        if (! $wabaId) {
            $fresh = array_values(array_diff($granted, WhatsAppAccount::whereIn('waba_id', $granted)->pluck('waba_id')->map(fn ($id) => (string) $id)->all()));
            $wabaId = match (true) {
                count($granted) === 1 => $granted[0],
                count($fresh) === 1 => $fresh[0],
                default => null,
            };

            if (! $wabaId && count($granted) > 1) {
                throw new EmbeddedSignupException('Meta did not say which WhatsApp Business Account you picked (you shared '.count($granted).'). Please run “Connect with Meta” again and wait until the popup closes by itself.');
            }
        }

        if (! $wabaId) {
            throw new EmbeddedSignupException('No WhatsApp Business Account was shared. Finish all steps in the Meta popup (including the phone number).');
        }

        $credentials = [
            'waba_id' => (string) $wabaId,
            'access_token' => $token,
            'app_secret' => $this->platform->appSecret(),
            'webhook_verify_token' => $this->platform->verifyToken() ?: Str::random(40),
            'graph_api_version' => $this->platform->graphVersion(),
        ];

        // The phone number id normally comes from the popup; otherwise take the WABA's first number.
        $phoneId = $input['phone_number_id'] ?? null;

        if (! $phoneId) {
            try {
                // Prefer a number of this WABA that is not connected here yet.
                $ids = collect(WhatsAppCloudApiService::for(new WhatsAppAccount($credentials))->getWabaPhoneNumbers())->pluck('id')->map(fn ($id) => (string) $id);
                $known = WhatsAppAccount::whereIn('phone_number_id', $ids)->pluck('phone_number_id')->map(fn ($id) => (string) $id);
                $phoneId = $ids->diff($known)->first() ?? ($ids->count() === 1 ? $ids->first() : null);
            } catch (WhatsAppApiException $e) {
                throw new EmbeddedSignupException('Could not read the phone numbers of the shared account: '.$e->userMessage());
            }
        }

        if (! $phoneId) {
            throw new EmbeddedSignupException('The shared WhatsApp Business Account has no phone number yet. Add a number in the Meta popup and try again.');
        }

        // Same number connected again → update it instead of creating a duplicate.
        $account = WhatsAppAccount::firstOrNew(['phone_number_id' => (string) $phoneId]);
        $created = ! $account->exists;

        if (! $created && ! $user->canAccessAccount($account)) {
            // Never let one customer take over a number that belongs to someone else.
            throw new EmbeddedSignupException('This WhatsApp number is already connected to another account on this platform.');
        }

        if ($created) {
            $this->planGate->ensureCanAddAccount($user, WhatsAppAccount::ONBOARD_EMBEDDED);
        }
        $account->fill($credentials + ['meta_business_id' => $input['business_id'] ?? $account->meta_business_id]);

        $api = WhatsAppCloudApiService::for($account);

        // 3. Webhooks for this WABA come to our app.
        try {
            $api->subscribeApp();
        } catch (WhatsAppApiException $e) {
            $warnings[] = 'Could not subscribe the app to the WABA webhooks yet: '.$e->userMessage().' Use “Subscribe app to WABA” on the account page.';
        }

        // 4. Register the number (not for WhatsApp Business app numbers — coexistence).
        [$registration, $registrationMessage] = $this->register($api, $input['event'] ?? null, $input['pin'] ?? null);

        if ($registration === 'failed') {
            $warnings[] = 'Phone registration: '.$registrationMessage;
        }

        // 5. Save as a normal WhatsApp account.
        $account->forceFill([
            'name' => $account->name ?: (filled($input['name'] ?? null) ? Str::limit($input['name'], 120, '') : 'WhatsApp '.$phoneId),
            'onboarding_method' => WhatsAppAccount::ONBOARD_EMBEDDED,
            'registration_status' => $registration,
            'registration_message' => $registrationMessage,
            'is_active' => true,
            'created_by' => $account->created_by ?? $user->id,
        ]);

        if ($this->platform->webhookVerifiedAt()) {
            // The shared platform webhook is already verified by Meta.
            $account->forceFill(['webhook_status' => WhatsAppAccount::WEBHOOK_VERIFIED, 'webhook_verified_at' => $account->webhook_verified_at ?? now()]);
        }

        if ($created && $user->isPlanLimited()) {
            $account->forceFill(['owner_id' => $user->id]);
        }

        $account->save();

        if ($account->owner_id) {
            $account->users()->syncWithoutDetaching([$account->owner_id]);
        }

        $result = $this->checker->run($account);
        $account->refresh();

        if ($result->ok && str_starts_with($account->name, 'WhatsApp ') && ($account->verified_name || $account->display_phone_number)) {
            $account->forceFill(['name' => $account->verified_name ?: $account->display_phone_number])->save();
        }

        if (! $result->ok) {
            $warnings[] = $result->message;
        }

        $this->logger->log(
            'account.embedded_signup',
            ($created ? 'Connected' : 'Re-connected')." WhatsApp number {$account->label()} via Embedded Signup",
            $account,
            ['waba_id' => $account->waba_id, 'phone_number_id' => $account->phone_number_id, 'registration' => $registration],
            $account->id,
            $user,
        );

        return ['account' => $account, 'created' => $created, 'warnings' => $warnings];
    }

    /**
     * Retry / perform phone registration later (from the account page).
     *
     * @return array{0:string, 1:string}
     */
    public function registerExisting(WhatsAppAccount $account, string $pin): array
    {
        [$status, $message] = $this->register(WhatsAppCloudApiService::for($account), self::EVENT_FINISH, $pin);

        $account->forceFill(['registration_status' => $status, 'registration_message' => $message])->save();

        $this->logger->log('account.registered', "Phone registration {$status} for {$account->label()}", $account, ['status' => $status], $account->id);

        return [$status, $message];
    }

    /**
     * GET /oauth/access_token?client_id&client_secret&code → business token.
     */
    public function exchangeCode(string $code): string
    {
        try {
            $response = Http::timeout(20)->acceptJson()->get($this->platform->graphUrl('oauth/access_token'), [
                'client_id' => $this->platform->appId(),
                'client_secret' => $this->platform->appSecret(),
                'code' => $code,
            ]);
        } catch (ConnectionException) {
            throw new EmbeddedSignupException('Could not reach Meta to finish the signup. Please try again.');
        }

        $token = $response->json('access_token');

        if ($response->failed() || ! is_string($token) || $token === '') {
            $reason = $response->json('error.message') ?? 'HTTP '.$response->status();

            throw new EmbeddedSignupException('Meta did not accept the signup code ('.$reason.'). The code is valid for 5 minutes — please click “Connect with Meta” again.');
        }

        return $token;
    }

    /**
     * WABA ids the business token may manage (debug_token → granular_scopes.target_ids).
     *
     * @return array<int, string>
     */
    public function grantedWabaIds(string $token): array
    {
        try {
            $response = Http::timeout(20)->acceptJson()->get($this->platform->graphUrl('debug_token'), [
                'input_token' => $token,
                'access_token' => $this->platform->appAccessToken(),
            ]);
        } catch (ConnectionException) {
            return [];
        }

        if ($response->failed()) {
            return [];
        }

        return collect($response->json('data.granular_scopes') ?? [])
            ->filter(fn ($s) => in_array($s['scope'] ?? '', ['whatsapp_business_management', 'whatsapp_business_messaging'], true))
            ->flatMap(fn ($s) => $s['target_ids'] ?? [])
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{0:string, 1:string} [status, message]
     */
    private function register(WhatsAppCloudApiService $api, ?string $event, ?string $pin): array
    {
        if ($event === self::EVENT_APP_ONBOARDING) {
            return ['coexistence', 'WhatsApp Business app number (coexistence) — no Cloud API registration needed.'];
        }

        if (! $pin || ! preg_match('/^\d{6}$/', $pin)) {
            return ['failed', 'No valid 6-digit PIN was given. Register the number from the account page.'];
        }

        try {
            $api->registerPhoneNumber($pin);

            return ['registered', 'Registered on Cloud API '.now()->toDayDateTimeString().'.'];
        } catch (WhatsAppApiException $e) {
            if (str_contains(strtolower($e->getMessage()), 'already registered')) {
                return ['registered', 'Number was already registered.'];
            }

            return ['failed', $e->userMessage().' If the number already has a two-step PIN, enter that PIN.'];
        }
    }
}
