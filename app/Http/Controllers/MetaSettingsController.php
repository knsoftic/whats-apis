<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\WhatsAppAccount;
use App\Services\ActivityLogger;
use App\Support\MetaPlatform;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/**
 * Settings → Meta Tech Provider: the platform's own Meta app used for
 * WhatsApp Embedded Signup and the shared webhook. Super Admin only.
 */
class MetaSettingsController extends Controller
{
    public function __construct(
        private readonly MetaPlatform $platform,
        private readonly ActivityLogger $logger,
    ) {}

    public function edit(): View
    {
        return view('settings.meta', [
            'platform' => $this->platform,
            'appSecretMasked' => WhatsAppAccount::mask((string) $this->platform->appSecret()),
            'suggestedVerifyToken' => Str::random(40),
            'embeddedAccounts' => WhatsAppAccount::where('onboarding_method', WhatsAppAccount::ONBOARD_EMBEDDED)->count(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'app_id' => ['required', 'regex:/^\d{5,30}$/'],
            'app_secret' => [filled($this->platform->appSecret()) ? 'nullable' : 'required', 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9]+$/'],
            'es_config_id' => ['required', 'regex:/^\d{5,30}$/'],
            'webhook_verify_token' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9_\-.~]+$/'],
            'graph_version' => ['required', 'regex:/^v\d{1,2}\.\d$/'],
        ], [
            'app_id.regex' => 'The App ID contains only digits.',
            'es_config_id.regex' => 'The Configuration ID contains only digits (Facebook Login for Business → Configurations).',
            'graph_version.regex' => 'Use the format v26.0',
        ]);

        $secretChanged = filled($data['app_secret'] ?? null) && $data['app_secret'] !== $this->platform->appSecret();
        $tokenChanged = $data['webhook_verify_token'] !== $this->platform->verifyToken();

        Setting::put(MetaPlatform::APP_ID, $data['app_id']);
        Setting::put(MetaPlatform::CONFIG_ID, $data['es_config_id']);
        Setting::put(MetaPlatform::VERIFY_TOKEN, $data['webhook_verify_token']);
        Setting::put(MetaPlatform::GRAPH_VERSION, $data['graph_version']);

        if (filled($data['app_secret'] ?? null)) {
            Setting::put(MetaPlatform::APP_SECRET, $data['app_secret']);
        }

        if ($secretChanged || $tokenChanged) {
            Setting::put(MetaPlatform::WEBHOOK_VERIFIED_AT, null);
        }

        // Numbers onboarded through Embedded Signup use the platform app's secret / verify token.
        if ($secretChanged || $tokenChanged) {
            WhatsAppAccount::where('onboarding_method', WhatsAppAccount::ONBOARD_EMBEDDED)->each(function (WhatsAppAccount $account) {
                $account->forceFill([
                    'app_secret' => $this->platform->appSecret(),
                    'webhook_verify_token' => $this->platform->verifyToken(),
                ])->save();
            });
        }

        $this->logger->log('settings.meta_updated', 'Updated Meta Tech Provider settings', properties: [
            'app_id' => $data['app_id'],
            'es_config_id' => $data['es_config_id'],
            'app_secret_changed' => $secretChanged,
        ]);

        return back()->with('success', 'Meta Tech Provider settings saved.'.($secretChanged || $tokenChanged ? ' Verify the webhook in Meta again.' : ''));
    }

    /**
     * Checks App ID + App Secret against Graph API (GET /{app-id} with the app access token).
     */
    public function test(): RedirectResponse
    {
        if (! filled($this->platform->appId()) || ! filled($this->platform->appSecret())) {
            return back()->with('error', 'Save the App ID and App Secret first.');
        }

        try {
            $response = Http::timeout(20)->acceptJson()->get($this->platform->graphUrl($this->platform->appId()), [
                'fields' => 'id,name',
                'access_token' => $this->platform->appAccessToken(),
            ]);
        } catch (Throwable) {
            return back()->with('error', 'Could not reach Meta Graph API from this server.');
        }

        if ($response->failed()) {
            return back()->with('error', 'Meta rejected the App ID / App Secret: '.($response->json('error.message') ?? 'HTTP '.$response->status()));
        }

        return back()->with('success', 'App credentials OK — connected to Meta app “'.$response->json('name').'”.');
    }
}
