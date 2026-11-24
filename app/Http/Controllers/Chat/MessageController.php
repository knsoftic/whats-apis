<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\WhatsAppAccount;
use App\Services\WhatsApp\MessageSender;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Outgoing messages from the dashboard (JSON endpoints used by the chat UI).
 * MessagingRestrictionException is rendered as HTTP 422 (see bootstrap/app.php).
 */
class MessageController extends Controller
{
    public function __construct(private readonly MessageSender $sender) {}

    public function storeText(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('send', $conversation);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:4096'],
            'reply_to_id' => ['nullable', 'integer'],
        ]);

        $message = $this->sender->sendText($conversation, trim($data['body']), $request->user(), $this->replyTarget($conversation, $data['reply_to_id'] ?? null));

        return $this->created($message);
    }

    public function storeMedia(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('send', $conversation);

        $data = $request->validate([
            // 100 MB = WhatsApp's largest limit (documents); per-type limits are checked in OutgoingMediaValidator.
            'file' => ['required', 'file', 'max:102400'],
            'caption' => ['nullable', 'string', 'max:1024'],
            'as' => ['nullable', 'in:document'],
            'reply_to_id' => ['nullable', 'integer'],
            'voice' => ['nullable', 'boolean'],
        ]);

        // Recorded with the mic button → WhatsApp voice note (OGG/Opus).
        $voice = $request->boolean('voice');
        $file = $voice ? app(\App\Services\WhatsApp\VoiceNoteConverter::class)->prepare($request->file('file')) : $request->file('file');

        $message = $this->sender->sendMedia(
            $conversation,
            $file,
            $request->user(),
            $data['caption'] ?? null,
            $this->replyTarget($conversation, $data['reply_to_id'] ?? null),
            $voice ? null : ($data['as'] ?? null),
            $voice,
        );

        // The converted temp file has been copied to storage by now.
        if ($voice && $file->getRealPath() !== $request->file('file')->getRealPath()) {
            @unlink((string) $file->getRealPath());
        }

        return $this->created($message);
    }

    public function storeTemplate(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('sendTemplate', $conversation);

        $data = $request->validate([
            'template_id' => ['required', 'integer'],
            'variables' => ['nullable', 'array'],
        ]);

        $template = MessageTemplate::where('whatsapp_account_id', $conversation->whatsapp_account_id)->findOrFail($data['template_id']);

        $message = $this->sender->sendTemplate($conversation, $template, $data['variables'] ?? [], $request->user());

        return $this->created($message);
    }

    /**
     * Start a new conversation with any phone number — template only (business-initiated).
     */
    public function start(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::TEMPLATES_SEND), 403);

        $data = $request->validate([
            'account_id' => ['required', 'integer'],
            'phone' => ['required', 'string', 'regex:/^\+?[0-9 ()\-]{8,20}$/'],
            'name' => ['nullable', 'string', 'max:120'],
            'template_id' => ['required', 'integer'],
            'variables' => ['nullable', 'array'],
        ], [
            'phone.regex' => 'Enter the full number with country code, e.g. +91 98765 43210.',
        ]);

        $account = $request->user()->accessibleAccounts()->findOrFail($data['account_id']);
        $template = MessageTemplate::where('whatsapp_account_id', $account->id)->findOrFail($data['template_id']);

        $digits = preg_replace('/\D+/', '', $data['phone']);

        if (strlen($digits) < 8 || strlen($digits) > 15) {
            return response()->json(['message' => 'Phone number must have 8–15 digits including country code.', 'errors' => ['phone' => ['Invalid phone number.']]], 422);
        }

        $conversation = $this->sender->conversationFor($account, $digits, $data['name'] ?? null);
        $message = $this->sender->sendTemplate($conversation, $template, $data['variables'] ?? [], $request->user());

        return response()->json([
            'conversation' => (new ConversationResource($conversation->fresh('contact')))->resolve(),
            'message' => (new MessageResource($message->load(['attachments', 'replyTo', 'sentBy'])))->resolve(),
        ], 201);
    }

    public function retry(Request $request, Message $message): JsonResponse
    {
        $this->authorize('send', $message->conversation);

        // fresh(): with a sync queue the job may already have updated the row.
        $message = $this->sender->retry($message)->fresh(['attachments', 'replyTo', 'sentBy']);

        return response()->json(['message' => (new MessageResource($message))->resolve()]);
    }

    private function replyTarget(Conversation $conversation, ?int $id): ?Message
    {
        return $id ? $conversation->messages()->find($id) : null;
    }

    private function created(Message $message): JsonResponse
    {
        $message->load(['attachments', 'replyTo', 'sentBy', 'conversation.contact']);

        return response()->json([
            'message' => (new MessageResource($message->fresh(['attachments', 'replyTo', 'sentBy'])))->resolve(),
            'conversation' => (new ConversationResource($message->conversation->fresh('contact')))->resolve(),
        ], 201);
    }
}
