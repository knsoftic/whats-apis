<?php

namespace App\Services\WhatsApp;

use App\Events\MessageUpdated;
use App\Exceptions\PlanLimitException;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsAppAccount;
use App\Services\PlanGate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Automatic follow-up before WhatsApp's 24-hour customer service window
 * closes: when the customer's last message is N hours old (default 23 h),
 * send one message inviting them to reply — a reply opens a new 24 h window.
 *
 * Exactly one follow-up per customer message (tracked with
 * conversations.followup_for_inbound_at); runs every 5 minutes.
 */
class FollowUpService
{
    /** Never send when the window closes within this many minutes (it could fail outside the window). */
    public const MIN_MINUTES_LEFT = 10;

    public const MIN_AFTER_MINUTES = 60;

    public const MAX_AFTER_MINUTES = 1410; // 23.5 h

    public const DEFAULT_MESSAGE = "Hi {{contact_name}}, just checking in — is there anything else we can help you with? Reply to this message and we'll continue the chat.";

    public function __construct(private readonly PlanGate $planGate) {}

    /**
     * @return int number of follow-ups queued
     */
    public function sendDue(?WhatsAppAccount $only = null): int
    {
        $sent = 0;

        WhatsAppAccount::query()
            ->where('followup_enabled', true)
            ->where('is_active', true)
            ->when($only, fn ($q) => $q->whereKey($only->id))
            ->each(function (WhatsAppAccount $account) use (&$sent) {
                $this->dueConversations($account)->with('contact')->chunkById(200, function ($conversations) use ($account, &$sent) {
                    foreach ($conversations as $conversation) {
                        $sent += $this->sendOne($account, $conversation) ? 1 : 0;
                    }
                });
            });

        return $sent;
    }

    /**
     * Chats whose last customer message is at least "after" minutes old, still inside
     * the 24 h window, and not followed up yet for that message.
     */
    public function dueConversations(WhatsAppAccount $account): \Illuminate\Database\Eloquent\Builder
    {
        $after = max(self::MIN_AFTER_MINUTES, min(self::MAX_AFTER_MINUTES, (int) $account->followup_after_minutes));

        return Conversation::query()
            ->where('whatsapp_account_id', $account->id)
            ->whereNotNull('last_inbound_at')
            ->where('last_inbound_at', '<=', now()->subMinutes($after))
            ->where('last_inbound_at', '>', now()->subMinutes(24 * 60 - self::MIN_MINUTES_LEFT))
            ->where(fn ($q) => $q->whereNull('followup_for_inbound_at')->orWhereColumn('followup_for_inbound_at', '!=', 'last_inbound_at'))
            ->when($account->followup_skip_closed, fn ($q) => $q->where('status', Conversation::STATUS_OPEN))
            ->whereHas('contact', fn ($q) => $q->whereNull('blocked_at')
                ->where(fn ($w) => $w->whereNull('opt_in_status')->orWhere('opt_in_status', '!=', Contact::OPT_OUT)));
    }

    private function sendOne(WhatsAppAccount $account, Conversation $conversation): bool
    {
        // Whatever happens, this customer message is handled (no retry every 5 minutes).
        $conversation->forceFill(['followup_for_inbound_at' => $conversation->last_inbound_at]);

        try {
            $this->planGate->ensureCanSend($account);
        } catch (PlanLimitException $e) {
            $conversation->save();
            Log::info('Follow-up skipped by plan limit', ['conversation' => $conversation->id, 'reason' => $e->getMessage()]);

            return false;
        }

        $contact = $conversation->contact;
        $text = $this->fill((string) ($account->followup_message ?: self::DEFAULT_MESSAGE), $account, $contact);

        $message = Message::create([
            'whatsapp_account_id' => $account->id,
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'direction' => Message::OUT,
            'type' => 'text',
            'body' => Str::limit($text, 4096, ''),
            'content' => ['followup' => true, 'preview_url' => (bool) preg_match('~https?://~i', $text)],
            'sender' => Contact::normalizeWaId((string) ($account->display_phone_number ?: $account->phone_number_id)),
            'recipient' => $contact->wa_id,
            'status' => Message::STATUS_PENDING,
            'wa_timestamp' => now(),
        ]);

        $conversation->forceFill([
            'followup_sent_at' => now(),
            'last_message_preview' => $message->previewText(),
            'last_message_direction' => Message::OUT,
            'last_message_at' => now(),
        ])->save();

        SendWhatsAppMessage::dispatch($message->id)->onQueue(config('whatsapp.queue'));
        MessageUpdated::dispatch($message);

        return true;
    }

    /** {{contact_name}}, {{phone}}, {{business_name}} */
    public function fill(string $text, WhatsAppAccount $account, ?Contact $contact): string
    {
        $vars = [
            'contact_name' => (string) ($contact?->name ?: $contact?->profile_name ?: 'there'),
            'phone' => (string) ($contact?->phone ?? ''),
            'business_name' => (string) ($account->verified_name ?: $account->name),
        ];

        return trim(preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/i', fn ($m) => $vars[strtolower($m[1])] ?? '', $text));
    }
}
