<?php

namespace App\Services;

use App\Exceptions\PlanLimitException;
use App\Models\BotFlow;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Plan;
use App\Models\User;
use App\Models\WhatsAppAccount;

/**
 * Single place where plan rules are checked.
 *
 *  - Staff with "accounts.access_all" (Super Admin / Admin) are never limited.
 *  - A WhatsApp number's limits are those of its OWNER (whatsapp_accounts.owner_id);
 *    numbers without an owner are platform-owned and unlimited.
 *  - NULL limit on a plan = unlimited.
 */
class PlanGate
{
    // ------------------------------------------------------------ who is limited

    public function ownerOf(WhatsAppAccount $account): ?User
    {
        $owner = $account->owner_id ? ($account->relationLoaded('owner') ? $account->owner : $account->owner()->first()) : null;

        return $owner && $owner->isPlanLimited() ? $owner : null;
    }

    private function requirePlan(User $user): Plan
    {
        $plan = $user->activePlan();

        if (! $plan) {
            $reason = $user->plan && $user->plan_expires_at?->isPast()
                ? 'Your plan expired on '.$user->plan_expires_at->toFormattedDateString().'.'
                : 'Your account has no active plan.';

            throw new PlanLimitException($reason.' Please contact the administrator to activate a plan.');
        }

        return $plan;
    }

    // ------------------------------------------------------------ WhatsApp numbers

    /**
     * @param  'manual'|'embedded_signup'  $method
     */
    public function ensureCanAddAccount(User $user, string $method): void
    {
        if (! $user->isPlanLimited()) {
            return;
        }

        $plan = $this->requirePlan($user);

        if ($method === WhatsAppAccount::ONBOARD_EMBEDDED && ! $plan->allow_embedded_signup) {
            throw new PlanLimitException("Your plan ({$plan->name}) does not include “Connect with Meta”. Use manual entry or upgrade your plan.");
        }

        if ($method === WhatsAppAccount::ONBOARD_MANUAL && ! $plan->allow_manual) {
            throw new PlanLimitException("Your plan ({$plan->name}) does not include adding numbers manually. Use “Connect with Meta” or upgrade your plan.");
        }

        if ($plan->max_whatsapp_accounts !== null && $user->ownedAccounts()->count() >= $plan->max_whatsapp_accounts) {
            throw new PlanLimitException("Your plan ({$plan->name}) allows {$plan->max_whatsapp_accounts} WhatsApp number(s). Remove one or upgrade your plan.");
        }
    }

    // ------------------------------------------------------------ messages

    public function messagesSentToday(User $owner): int
    {
        return Message::query()
            ->whereIn('whatsapp_account_id', $owner->ownedAccounts()->select('id'))
            ->where('direction', Message::OUT)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();
    }

    /**
     * Throws when sending $count more messages would exceed the owner's daily limit.
     */
    public function ensureCanSend(WhatsAppAccount $account, int $count = 1): void
    {
        $owner = $this->ownerOf($account);

        if (! $owner) {
            return;
        }

        $plan = $this->requirePlan($owner);

        if ($plan->daily_message_limit !== null && $this->messagesSentToday($owner) + $count > $plan->daily_message_limit) {
            throw new PlanLimitException("Daily message limit reached ({$plan->daily_message_limit} per day on plan {$plan->name}). It resets at midnight, or upgrade your plan.");
        }
    }

    // ------------------------------------------------------------ contacts

    public function ensureCanAddContacts(WhatsAppAccount $account, int $count = 1): void
    {
        $owner = $this->ownerOf($account);

        if (! $owner) {
            return;
        }

        $plan = $this->requirePlan($owner);

        if ($plan->max_contacts === null) {
            return;
        }

        $current = Contact::whereIn('whatsapp_account_id', $owner->ownedAccounts()->select('id'))->count();

        if ($current + $count > $plan->max_contacts) {
            throw new PlanLimitException("Your plan ({$plan->name}) allows {$plan->max_contacts} contacts (you have {$current}). Upgrade to add more.");
        }
    }

    public function remainingContacts(WhatsAppAccount $account): ?int
    {
        $owner = $this->ownerOf($account);
        $plan = $owner?->activePlan();

        if (! $owner || ! $plan || $plan->max_contacts === null) {
            return null;
        }

        return max(0, $plan->max_contacts - Contact::whereIn('whatsapp_account_id', $owner->ownedAccounts()->select('id'))->count());
    }

    // ------------------------------------------------------------ bots

    public function botsAllowed(WhatsAppAccount $account): bool
    {
        $owner = $this->ownerOf($account);

        if (! $owner) {
            return true;
        }

        $plan = $owner->activePlan();

        return $plan !== null && $plan->allow_bot_flows;
    }

    public function ensureCanCreateBotFlow(WhatsAppAccount $account): void
    {
        $owner = $this->ownerOf($account);

        if (! $owner) {
            return;
        }

        $plan = $this->requirePlan($owner);

        if (! $plan->allow_bot_flows) {
            throw new PlanLimitException("Bot flows are not included in your plan ({$plan->name}). Upgrade to use them.");
        }

        $count = BotFlow::whereIn('whatsapp_account_id', $owner->ownedAccounts()->select('id'))->count();

        if ($plan->max_bot_flows !== null && $count >= $plan->max_bot_flows) {
            throw new PlanLimitException("Your plan ({$plan->name}) allows {$plan->max_bot_flows} bot flow(s).");
        }
    }

    // ------------------------------------------------------------ dashboard

    /**
     * @return array<string, mixed>|null  null for unlimited staff users
     */
    public function usage(User $user): ?array
    {
        if (! $user->isPlanLimited() || ! $user->hasRole(\App\Support\Permissions::ROLE_CUSTOMER)) {
            return null;
        }

        $plan = $user->plan;

        return [
            'plan' => $plan,
            'active' => $user->activePlan() !== null,
            'expires_at' => $user->plan_expires_at,
            'messages_today' => $this->messagesSentToday($user),
            'daily_limit' => $plan?->daily_message_limit,
            'accounts' => $user->ownedAccounts()->count(),
            'max_accounts' => $plan?->max_whatsapp_accounts,
            'contacts' => Contact::whereIn('whatsapp_account_id', $user->ownedAccounts()->select('id'))->count(),
            'max_contacts' => $plan?->max_contacts,
            'bot_flows' => BotFlow::whereIn('whatsapp_account_id', $user->ownedAccounts()->select('id'))->count(),
            'max_bot_flows' => $plan?->max_bot_flows,
            'bots_allowed' => (bool) $plan?->allow_bot_flows,
        ];
    }
}
