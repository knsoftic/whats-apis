<?php

namespace App\Http\Controllers\Chat;

use App\Events\ConversationRead;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Jobs\SendReadReceipt;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\WhatsAppAccount;
use App\Support\CurrentAccount;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * WhatsApp Web-style inbox. The page is Blade + Alpine; data comes from the
 * JSON endpoints below. Every query is scoped to an account the user can access.
 */
class ChatController extends Controller
{
    public function index(Request $request, CurrentAccount $current): View
    {
        $accounts = $current->accounts();
        $selected = null;

        if ($request->filled('conversation')) {
            $conversation = Conversation::find((int) $request->query('conversation'));

            if ($conversation && $request->user()->can('view', $conversation)) {
                $selected = $conversation;
            }
        }

        $account = $selected?->account
            ?? ($request->filled('account') ? $accounts->firstWhere('id', (int) $request->query('account')) : null)
            ?? $current->getOrFirst();

        return view('chat.index', [
            'accounts' => $accounts->map(fn (WhatsAppAccount $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'phone' => $a->display_phone_number,
                'active' => $a->is_active,
                'operational' => $a->isOperational(),
            ])->values(),
            'accountId' => $account?->id,
            'conversationId' => $selected?->id,
            'can' => [
                'send' => $request->user()->can(Permissions::CHATS_SEND),
                'template' => $request->user()->can(Permissions::TEMPLATES_SEND),
                'contacts' => $request->user()->can(Permissions::CONTACTS_VIEW),
            ],
        ]);
    }

    public function conversations(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
            'filter' => ['nullable', 'in:all,unread,open,closed'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $account = $this->account($request, (int) $data['account_id']);
        $term = trim((string) ($data['q'] ?? ''));

        $page = Conversation::query()
            ->with('contact')
            ->where('whatsapp_account_id', $account->id)
            ->when($term !== '', fn (Builder $q) => $q->whereHas('contact', fn (Builder $c) => $c->search($term)))
            ->when(($data['filter'] ?? 'all') === 'unread', fn (Builder $q) => $q->where('unread_count', '>', 0))
            ->when(($data['filter'] ?? 'all') === 'open', fn (Builder $q) => $q->where('status', Conversation::STATUS_OPEN))
            ->when(($data['filter'] ?? 'all') === 'closed', fn (Builder $q) => $q->where('status', Conversation::STATUS_CLOSED))
            ->orderByRaw('last_message_at IS NULL')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->simplePaginate(30);

        return response()->json([
            'data' => ConversationResource::collection($page->getCollection())->resolve(),
            'has_more' => $page->hasMorePages(),
            'unread_total' => Conversation::where('whatsapp_account_id', $account->id)->where('unread_count', '>', 0)->count(),
        ]);
    }

    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $data = $request->validate([
            'before_id' => ['nullable', 'integer'],
            'after_id' => ['nullable', 'integer'],
        ]);

        $limit = 40;
        $query = $conversation->messages()->with(['attachments', 'replyTo', 'sentBy:id,name'])->where('type', '!=', 'reaction');

        if (! empty($data['after_id'])) {
            $messages = $query->where('id', '>', $data['after_id'])->orderBy('id')->limit(200)->get();
            $hasMore = false;
        } else {
            $messages = $query
                ->when($data['before_id'] ?? null, fn ($q, $id) => $q->where('id', '<', $id))
                ->orderByDesc('id')
                ->limit($limit + 1)
                ->get();
            $hasMore = $messages->count() > $limit;
            $messages = $messages->take($limit)->reverse()->values();
        }

        // Attach reactions to the messages they react to.
        $reactions = Message::where('conversation_id', $conversation->id)
            ->where('type', 'reaction')
            ->get(['content', 'direction'])
            ->groupBy(fn ($r) => $r->content['message_id'] ?? '')
            ->map(fn ($group) => $group->pluck('content.emoji')->filter()->unique()->values());

        $payload = MessageResource::collection($messages)->resolve();

        foreach ($payload as &$m) {
            $m['reactions'] = $m['wa_message_id'] ? ($reactions[$m['wa_message_id']] ?? []) : [];
        }

        return response()->json([
            'conversation' => (new ConversationResource($conversation->load('contact')))->resolve(),
            'messages' => $payload,
            'has_more' => $hasMore,
        ]);
    }

    public function markRead(Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $latestInbound = $conversation->messages()
            ->where('direction', Message::IN)
            ->whereNull('read_at')
            ->latest('id')
            ->first();

        if ($conversation->unread_count > 0) {
            $conversation->forceFill(['unread_count' => 0])->save();
            ConversationRead::dispatch($conversation);
        }

        // Blue ticks for the customer — only if this user may act in the conversation.
        if ($latestInbound && request()->user()->can('send', $conversation)) {
            SendReadReceipt::dispatch($latestInbound->id)->onQueue(config('whatsapp.queue'));
        }

        return response()->json(['ok' => true, 'unread_count' => 0]);
    }

    public function markUnread(Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $conversation);

        $conversation->forceFill(['unread_count' => max(1, $conversation->unread_count)])->save();

        return response()->json(['ok' => true, 'unread_count' => $conversation->unread_count]);
    }

    public function update(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $conversation);

        $data = $request->validate(['status' => ['required', 'in:open,closed']]);
        $conversation->forceFill(['status' => $data['status']])->save();

        return response()->json(['conversation' => (new ConversationResource($conversation->load('contact')))->resolve()]);
    }

    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer'],
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $account = $this->account($request, (int) $data['account_id']);
        $term = $data['q'];

        $results = Message::query()
            ->with('contact:id,name,profile_name,phone')
            ->where('whatsapp_account_id', $account->id)
            ->where('body', 'like', '%'.addcslashes($term, '%_\\').'%')
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(fn (Message $m) => [
                'message_id' => $m->id,
                'conversation_id' => $m->conversation_id,
                'contact' => $m->contact?->displayName(),
                'direction' => $m->direction,
                'snippet' => $this->snippet((string) $m->body, $term),
                'timestamp' => ($m->wa_timestamp ?? $m->created_at)?->toIso8601String(),
            ]);

        return response()->json(['data' => $results]);
    }

    /**
     * Approved templates of an account, shaped for the "send template" form.
     */
    public function templates(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::TEMPLATES_SEND), 403);

        $account = $this->account($request, (int) $request->validate(['account_id' => ['required', 'integer']])['account_id']);

        $templates = MessageTemplate::where('whatsapp_account_id', $account->id)
            ->where('status', MessageTemplate::APPROVED)
            ->orderBy('name')
            ->get()
            ->map(fn (MessageTemplate $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'language' => $t->language,
                'category' => $t->category,
                'header_format' => $t->headerFormat(),
                'header_text' => $t->header()['text'] ?? null,
                'header_placeholders' => $t->headerPlaceholders(),
                'body_text' => $t->body()['text'] ?? '',
                'body_placeholders' => $t->bodyPlaceholders(),
                'footer_text' => $t->footer()['text'] ?? null,
                'buttons' => collect($t->buttons())->map(fn ($b) => ['type' => $b['type'] ?? '', 'text' => $b['text'] ?? ''])->values(),
                'dynamic_buttons' => $t->dynamicUrlButtons(),
            ]);

        return response()->json(['data' => $templates]);
    }

    private function account(Request $request, int $id): WhatsAppAccount
    {
        // findOrFail on the user's accessible accounts → 404 for anything else (no leakage).
        return $request->user()->accessibleAccounts()->findOrFail($id);
    }

    private function snippet(string $body, string $term): string
    {
        $pos = mb_stripos($body, $term);
        $start = max(0, ($pos === false ? 0 : $pos) - 40);

        return ($start > 0 ? '…' : '').Str::limit(mb_substr($body, $start), 140);
    }
}
