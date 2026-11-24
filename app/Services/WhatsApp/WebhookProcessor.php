<?php

namespace App\Services\WhatsApp;

use App\Events\MessageReceived;
use App\Events\MessageStatusUpdated;
use App\Jobs\DownloadMessageMedia;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageTemplate;
use App\Models\WebhookEvent;
use App\Models\WhatsAppAccount;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Applies a stored, signature-verified webhook payload to the database.
 * Idempotent: running the same payload twice changes nothing.
 */
class WebhookProcessor
{
    /** Keywords that change the contact's opt-in status. */
    private const OPT_OUT_WORDS = ['stop', 'unsubscribe', 'stop all', 'opt out', 'optout'];

    private const OPT_IN_WORDS = ['start', 'subscribe', 'opt in', 'optin'];

    public function process(WebhookEvent $event): void
    {
        $receiver = $event->account;
        $payload = $event->decodedPayload();

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $field = $change['field'] ?? 'messages';
                $value = $change['value'] ?? [];

                match ($field) {
                    'messages' => $this->handleMessagesChange($receiver, $value),
                    'message_template_status_update' => $this->handleTemplateStatus($receiver, $value),
                    'phone_number_quality_update' => $this->handleQualityUpdate($receiver, $value),
                    default => Log::info('WhatsApp webhook field ignored', ['field' => $field, 'account' => $receiver->id]),
                };
            }
        }
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function handleMessagesChange(WhatsAppAccount $receiver, array $value): void
    {
        $account = $this->resolveAccount($receiver, (string) ($value['metadata']['phone_number_id'] ?? ''));

        if (! $account) {
            return;
        }

        $profiles = collect($value['contacts'] ?? [])->keyBy('wa_id');

        foreach ($value['messages'] ?? [] as $msg) {
            $this->storeIncomingMessage($account, $msg, $profiles->get($msg['from'] ?? '') ?? []);
        }

        foreach ($value['statuses'] ?? [] as $status) {
            $this->applyStatus($account, $status);
        }

        foreach ($value['errors'] ?? [] as $error) {
            Log::warning('WhatsApp webhook reported an error', ['account' => $account->id, 'code' => $error['code'] ?? null, 'title' => $error['title'] ?? null]);
        }
    }

    /**
     * One Meta app has ONE callback URL, so events for several numbers may
     * arrive at one account's URL. Route by phone_number_id — but only to an
     * account that shares the same App Secret (i.e. the same, signature-verified app).
     */
    private function resolveAccount(WhatsAppAccount $receiver, string $phoneNumberId): ?WhatsAppAccount
    {
        if ($phoneNumberId === '' || $phoneNumberId === (string) $receiver->phone_number_id) {
            return $receiver;
        }

        $target = WhatsAppAccount::where('phone_number_id', $phoneNumberId)->first();

        if ($target && hash_equals((string) $target->app_secret, (string) $receiver->app_secret)) {
            $target->forceFill(['last_webhook_at' => now()])->save();

            return $target;
        }

        Log::warning('WhatsApp webhook for an unknown phone number ignored', [
            'receiver_account' => $receiver->id,
            'phone_number_id' => $phoneNumberId,
        ]);

        return null;
    }

    /**
     * @param  array<string, mixed>  $msg
     * @param  array<string, mixed>  $profile
     */
    public function storeIncomingMessage(WhatsAppAccount $account, array $msg, array $profile = []): ?Message
    {
        $wamid = (string) ($msg['id'] ?? '');
        $from = Contact::normalizeWaId((string) ($msg['from'] ?? ''));

        if ($wamid === '' || $from === '') {
            return null;
        }

        if (Message::where('whatsapp_account_id', $account->id)->where('wa_message_id', $wamid)->exists()) {
            return null; // duplicate delivery
        }

        $parsed = IncomingMessageParser::parse($msg);
        $sentAt = isset($msg['timestamp']) ? Carbon::createFromTimestamp((int) $msg['timestamp']) : now();

        try {
            [$message, $attachment] = DB::transaction(function () use ($account, $msg, $from, $wamid, $parsed, $sentAt, $profile) {
                $contact = Contact::firstOrCreate(
                    ['whatsapp_account_id' => $account->id, 'wa_id' => $from],
                    ['phone' => Contact::formatPhone($from), 'opt_in_status' => Contact::OPT_UNKNOWN],
                );

                $profileName = $profile['profile']['name'] ?? null;

                if ($profileName && $contact->profile_name !== $profileName) {
                    $contact->profile_name = Str::limit($profileName, 250, '');
                }

                $this->applyOptKeywords($contact, $parsed);
                $contact->save();

                $conversation = Conversation::firstOrCreate(
                    ['whatsapp_account_id' => $account->id, 'contact_id' => $contact->id],
                    ['status' => Conversation::STATUS_OPEN],
                );

                $replyTo = $parsed['context']
                    ? Message::where('whatsapp_account_id', $account->id)->where('wa_message_id', $parsed['context'])->value('id')
                    : null;

                $message = Message::create([
                    'whatsapp_account_id' => $account->id,
                    'conversation_id' => $conversation->id,
                    'contact_id' => $contact->id,
                    'wa_message_id' => $wamid,
                    'direction' => Message::IN,
                    'type' => $parsed['type'],
                    'sender' => $from,
                    'recipient' => Contact::normalizeWaId((string) ($account->display_phone_number ?: $account->phone_number_id)),
                    'body' => $parsed['body'],
                    'content' => $parsed['content'],
                    'context_wa_message_id' => $parsed['context'],
                    'reply_to_message_id' => $replyTo,
                    'status' => Message::STATUS_RECEIVED,
                    'wa_timestamp' => $sentAt,
                ]);

                // Leads sheet: first message from this number → saved once (name + number + message).
                \App\Models\Lead::capture($account, $contact, $message, $sentAt);

                $attachment = null;

                if ($parsed['media']) {
                    $attachment = MessageAttachment::create([
                        'message_id' => $message->id,
                        'wa_media_id' => $parsed['media']['id'],
                        'type' => $parsed['type'],
                        'mime_type' => $parsed['media']['mime_type'],
                        'sha256' => $parsed['media']['sha256'],
                        'file_name' => $parsed['media']['filename'],
                        'disk' => config('whatsapp.media.disk'),
                        'download_status' => MessageAttachment::PENDING,
                    ]);
                }

                // Reactions don't move the conversation or count as unread.
                if ($parsed['type'] !== 'reaction') {
                    $isNewest = ! $conversation->last_message_at || $sentAt->gte($conversation->last_message_at);

                    $conversation->forceFill([
                        'status' => Conversation::STATUS_OPEN,
                        'last_inbound_at' => self::later($conversation->last_inbound_at, $sentAt),
                        'unread_count' => $conversation->unread_count + 1,
                    ] + ($isNewest ? [
                        'last_message_preview' => $message->previewText(),
                        'last_message_direction' => Message::IN,
                        'last_message_at' => $sentAt,
                    ] : []))->save();
                } else {
                    // Customer activity still opens the 24h service window.
                    $conversation->forceFill(['last_inbound_at' => self::later($conversation->last_inbound_at, $sentAt)])->save();
                }

                return [$message, $attachment];
            });
        } catch (UniqueConstraintViolationException) {
            return null; // Same message processed concurrently by another worker.
        }

        if ($attachment) {
            DownloadMessageMedia::dispatch($attachment->id)->onQueue(config('whatsapp.queue'));
        }

        MessageReceived::dispatch($message);

        if (\App\Models\BotFlow::where('whatsapp_account_id', $account->id)->where('is_active', true)->exists()) {
            \App\Jobs\RunBotFlow::dispatch($message->id)->onQueue(config('whatsapp.queue'));
        }

        return $message;
    }

    private static function later(?Carbon $current, Carbon $candidate): Carbon
    {
        return $current && $current->gt($candidate) ? $current : $candidate;
    }

    /**
     * @param  array{type:string, body:?string}  $parsed
     */
    private function applyOptKeywords(Contact $contact, array $parsed): void
    {
        if ($parsed['type'] !== 'text' && $parsed['type'] !== 'button') {
            return;
        }

        $word = strtolower(trim((string) $parsed['body']));

        if (in_array($word, self::OPT_OUT_WORDS, true)) {
            $contact->forceFill(['opt_in_status' => Contact::OPT_OUT, 'opted_out_at' => now(), 'opt_in_source' => 'whatsapp_keyword']);
        } elseif (in_array($word, self::OPT_IN_WORDS, true)) {
            $contact->forceFill(['opt_in_status' => Contact::OPT_IN, 'opted_in_at' => now(), 'opt_in_source' => 'whatsapp_keyword']);
        }
    }

    /**
     * @param  array<string, mixed>  $status
     */
    public function applyStatus(WhatsAppAccount $account, array $status): ?Message
    {
        $wamid = (string) ($status['id'] ?? '');
        $new = strtolower((string) ($status['status'] ?? ''));

        if ($wamid === '' || ! in_array($new, [Message::STATUS_SENT, Message::STATUS_DELIVERED, Message::STATUS_READ, Message::STATUS_FAILED], true)) {
            return null;
        }

        $message = Message::where('whatsapp_account_id', $account->id)->where('wa_message_id', $wamid)->first();

        if (! $message) {
            // Sent from another tool (e.g. WhatsApp Manager) — nothing to update.
            return null;
        }

        $at = isset($status['timestamp']) ? Carbon::createFromTimestamp((int) $status['timestamp']) : now();
        $changes = [];

        // Fill timestamps for every step the new status implies.
        if (in_array($new, [Message::STATUS_SENT, Message::STATUS_DELIVERED, Message::STATUS_READ], true)) {
            $changes['sent_at'] = $message->sent_at ?? $at;
        }

        if (in_array($new, [Message::STATUS_DELIVERED, Message::STATUS_READ], true)) {
            $changes['delivered_at'] = $message->delivered_at ?? $at;
        }

        if ($new === Message::STATUS_READ) {
            $changes['read_at'] = $message->read_at ?? $at;
        }

        // Never move backwards (e.g. late "delivered" after "read").
        if ($message->status !== Message::STATUS_FAILED && Message::statusRank($new) > Message::statusRank($message->status)) {
            $changes['status'] = $new;
        }

        if ($new === Message::STATUS_FAILED) {
            $error = $status['errors'][0] ?? [];
            $changes['status'] = Message::STATUS_FAILED;
            $changes['failed_at'] = $at;
            $changes['error_code'] = isset($error['code']) ? (string) $error['code'] : null;
            $changes['error_message'] = Str::limit(trim(($error['title'] ?? $error['message'] ?? 'Failed').(isset($error['error_data']['details']) ? ' — '.$error['error_data']['details'] : '')), 1000, '');
        }

        $message->forceFill($changes);

        if (! $message->isDirty()) {
            return $message; // duplicate status webhook
        }

        $message->save();

        MessageStatusUpdated::dispatch($message);

        return $message;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function handleTemplateStatus(WhatsAppAccount $account, array $value): void
    {
        $query = MessageTemplate::where('whatsapp_account_id', $account->id);

        if (! empty($value['message_template_id'])) {
            $query->where('meta_template_id', (string) $value['message_template_id']);
        } else {
            $query->where('name', $value['message_template_name'] ?? '')->where('language', $value['message_template_language'] ?? '');
        }

        $query->update([
            'status' => strtoupper((string) ($value['event'] ?? '')),
            'rejected_reason' => ($value['reason'] ?? null) && $value['reason'] !== 'NONE' ? Str::limit($value['reason'], 250, '') : null,
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function handleQualityUpdate(WhatsAppAccount $account, array $value): void
    {
        $meta = $account->phone_meta ?? [];
        $meta['quality_event'] = $value['event'] ?? null;
        $meta['messaging_limit'] = $value['current_limit'] ?? null;
        $meta['quality_event_at'] = now()->toIso8601String();

        $account->forceFill(['phone_meta' => $meta])->save();
    }
}
