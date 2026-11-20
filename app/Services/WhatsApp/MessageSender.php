<?php

namespace App\Services\WhatsApp;

use App\Events\MessageUpdated;
use App\Exceptions\MessagingRestrictionException;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Services\ActivityLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates outgoing messages (status "pending") and queues them for delivery.
 * All WhatsApp policy checks happen here, BEFORE anything is sent.
 */
class MessageSender
{
    public function __construct(
        private readonly OutgoingMediaValidator $mediaValidator,
        private readonly TemplateMessageBuilder $templateBuilder,
        private readonly ActivityLogger $logger,
        private readonly \App\Services\PlanGate $planGate,
    ) {}

    public function sendText(Conversation $conversation, string $text, User $user, ?Message $replyTo = null): Message
    {
        $this->ensureCanSendFreeForm($conversation);

        return $this->createAndQueue($conversation, $user, [
            'type' => 'text',
            'body' => $text,
            'content' => ['preview_url' => (bool) preg_match('~https?://~i', $text)],
        ], $replyTo);
    }

    public function sendMedia(Conversation $conversation, UploadedFile $file, User $user, ?string $caption = null, ?Message $replyTo = null, ?string $asType = null): Message
    {
        $this->ensureCanSendFreeForm($conversation);

        ['type' => $type, 'mime' => $mime] = $this->mediaValidator->resolve($file, $asType);

        if ($type === 'audio') {
            $caption = null; // WhatsApp audio messages have no caption
        }

        $original = Str::limit(preg_replace('/[^\w.\- ()]+/u', '_', $file->getClientOriginalName()) ?: 'file', 200, '');
        $extension = strtolower($file->getClientOriginalExtension()) ?: 'bin';
        $directory = sprintf('%s/%d/outgoing/%s', config('whatsapp.media.directory'), $conversation->whatsapp_account_id, now()->format('Y/m'));
        $path = $file->storeAs($directory, Str::uuid().'.'.$extension, ['disk' => config('whatsapp.media.disk')]);

        return $this->createAndQueue($conversation, $user, [
            'type' => $type,
            'body' => $caption !== null && $caption !== '' ? Str::limit($caption, 1024, '') : null,
            'content' => $type === 'document' ? ['filename' => $original] : null,
        ], $replyTo, [
            'type' => $type,
            'mime_type' => $mime,
            'file_name' => $original,
            'size' => $file->getSize(),
            'disk' => config('whatsapp.media.disk'),
            'path' => $path,
            'download_status' => MessageAttachment::DOWNLOADED,
        ]);
    }

    /**
     * Approved templates may be sent at any time (business-initiated), except
     * to contacts who opted out.
     *
     * @param  array<string, mixed>  $variables
     */
    public function sendTemplate(Conversation $conversation, MessageTemplate $template, array $variables, User $user): Message
    {
        $account = $conversation->account;

        if (! $account->is_active) {
            throw MessagingRestrictionException::accountDisabled();
        }

        if ($template->whatsapp_account_id !== $conversation->whatsapp_account_id) {
            throw new MessagingRestrictionException('This template belongs to a different WhatsApp account.');
        }

        if (! $template->isApproved()) {
            throw MessagingRestrictionException::templateNotApproved();
        }

        if ($conversation->contact->opt_in_status === Contact::OPT_OUT && ! $conversation->isWithinServiceWindow()) {
            throw MessagingRestrictionException::optedOut();
        }

        $built = $this->templateBuilder->build($template, $variables);

        return $this->createAndQueue($conversation, $user, [
            'type' => 'template',
            'body' => Str::limit($built['preview'], 4096, ''),
            'content' => [
                'name' => $template->name,
                'language' => $template->language,
                'template_id' => $template->id,
                'components' => $built['components'],
            ],
        ]);
    }

    /**
     * Find or create the contact + conversation for a phone number (to start a new chat with a template).
     */
    public function conversationFor(WhatsAppAccount $account, string $phone, ?string $name = null): Conversation
    {
        $waId = Contact::normalizeWaId($phone);

        return DB::transaction(function () use ($account, $waId, $name) {
            $contact = Contact::firstOrCreate(
                ['whatsapp_account_id' => $account->id, 'wa_id' => $waId],
                ['phone' => Contact::formatPhone($waId), 'name' => $name, 'opt_in_status' => Contact::OPT_UNKNOWN],
            );

            return Conversation::firstOrCreate(
                ['whatsapp_account_id' => $account->id, 'contact_id' => $contact->id],
                ['status' => Conversation::STATUS_OPEN],
            );
        });
    }

    /**
     * Re-queue a failed message (same content, new attempt).
     */
    public function retry(Message $message): Message
    {
        if ($message->direction !== Message::OUT || $message->status !== Message::STATUS_FAILED) {
            throw new MessagingRestrictionException('Only failed outgoing messages can be retried.');
        }

        if ($message->type !== 'template') {
            $this->ensureCanSendFreeForm($message->conversation);
        }

        $this->planGate->ensureCanSend($message->account);

        $message->forceFill([
            'status' => Message::STATUS_PENDING,
            'wa_message_id' => null,
            'error_code' => null,
            'error_message' => null,
            'failed_at' => null,
        ])->save();

        SendWhatsAppMessage::dispatch($message->id)->onQueue(config('whatsapp.queue'));
        MessageUpdated::dispatch($message);

        return $message;
    }

    public function ensureCanSendFreeForm(Conversation $conversation): void
    {
        if (! $conversation->account->is_active) {
            throw MessagingRestrictionException::accountDisabled();
        }

        if (! $conversation->isWithinServiceWindow()) {
            throw MessagingRestrictionException::windowClosed();
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|null  $attachment
     */
    private function createAndQueue(Conversation $conversation, User $user, array $attributes, ?Message $replyTo = null, ?array $attachment = null): Message
    {
        $account = $conversation->account;
        $contact = $conversation->contact;

        if ($replyTo && $replyTo->conversation_id !== $conversation->id) {
            $replyTo = null; // never reply to a message from another conversation
        }

        $this->planGate->ensureCanSend($account);

        $message = DB::transaction(function () use ($conversation, $user, $attributes, $replyTo, $attachment, $account, $contact) {
            $message = Message::create(array_merge([
                'whatsapp_account_id' => $account->id,
                'conversation_id' => $conversation->id,
                'contact_id' => $contact->id,
                'direction' => Message::OUT,
                'sender' => Contact::normalizeWaId((string) ($account->display_phone_number ?: $account->phone_number_id)),
                'recipient' => $contact->wa_id,
                'status' => Message::STATUS_PENDING,
                'sent_by_user_id' => $user->id,
                'reply_to_message_id' => $replyTo?->id,
                'context_wa_message_id' => $replyTo?->wa_message_id,
                'wa_timestamp' => now(),
            ], $attributes));

            if ($attachment) {
                $message->attachments()->create($attachment);
            }

            $conversation->forceFill([
                'status' => Conversation::STATUS_OPEN,
                'last_message_preview' => $message->previewText(),
                'last_message_direction' => Message::OUT,
                'last_message_at' => now(),
                // A human replied → bots stay quiet in this chat for a while.
                'bot_paused_until' => now()->addMinutes((int) config('whatsapp.bot.pause_after_agent_minutes', 60)),
            ])->save();

            return $message;
        });

        $this->logger->log('message.queued', "Queued {$message->type} message to {$contact->phone}", $message, [
            'conversation_id' => $conversation->id,
        ], $account->id, $user);

        SendWhatsAppMessage::dispatch($message->id)->onQueue(config('whatsapp.queue'));
        MessageUpdated::dispatch($message);

        return $message;
    }
}
