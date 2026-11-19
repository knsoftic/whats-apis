<?php

namespace App\Actions\WhatsApp;

use App\Models\WhatsAppAccount;
use App\Services\ActivityLogger;
use App\Services\WhatsApp\CredentialCheckResult;
use App\Services\WhatsApp\WhatsAppCloudApiService;
use Illuminate\Support\Arr;

/**
 * Runs validateCredentials() against Meta and stores the outcome on the account.
 * API status and app-subscription status are stored separately; webhook
 * verification status is only ever set by Meta calling our webhook.
 */
class CheckAccountConnection
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function run(WhatsAppAccount $account): CredentialCheckResult
    {
        $result = WhatsAppCloudApiService::for($account)->validateCredentials();

        $attributes = [
            'api_status' => $result->ok ? WhatsAppAccount::API_CONNECTED : WhatsAppAccount::API_FAILED,
            'api_status_message' => trim($result->message.' '.implode(' ', $result->warnings)),
            'api_checked_at' => now(),
        ];

        if ($result->ok) {
            $phone = $result->phone;
            $attributes += [
                'display_phone_number' => $phone['display_phone_number'] ?? $account->display_phone_number,
                'verified_name' => $phone['verified_name'] ?? $account->verified_name,
                'quality_rating' => $phone['quality_rating'] ?? null,
                'phone_meta' => Arr::only($phone, ['code_verification_status', 'platform_type', 'throughput', 'name_status']),
                'subscription_status' => match ($result->subscribed) {
                    true => WhatsAppAccount::SUB_SUBSCRIBED,
                    false => WhatsAppAccount::SUB_NOT_SUBSCRIBED,
                    null => WhatsAppAccount::SUB_FAILED,
                },
                'subscription_status_message' => $result->subscriptionMessage,
                'subscription_checked_at' => now(),
            ];
        }

        $account->forceFill($attributes)->save();

        $this->logger->log(
            'account.checked',
            ($result->ok ? 'API check passed' : 'API check failed')." for {$account->name}",
            $account,
            ['ok' => $result->ok, 'message' => $result->message],
            $account->id,
        );

        return $result;
    }
}
