<?php

namespace App\Http\Controllers;

use App\Models\AutoReply;
use App\Models\BotFlow;
use App\Models\WhatsAppAccount;
use App\Services\ActivityLogger;
use App\Services\PlanGate;
use App\Support\BotGraph;
use App\Support\CurrentAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Illuminate\View\View;

/**
 * Auto Replies (always reply / auto reply / quick action buttons) — rules per
 * WhatsApp number, managed here and answered by AutoReplyEngine. Not Bot Flows.
 */
class AutoReplyController extends Controller
{
    public function __construct(
        private readonly PlanGate $planGate,
        private readonly ActivityLogger $logger,
    ) {}

    public function index(Request $request, CurrentAccount $current): View
    {
        $accounts = $current->accounts();
        $accountId = $request->integer('account_id') ?: $current->getOrFirst()?->id;
        abort_if($accountId && ! $accounts->contains('id', $accountId), 404);
        $account = $accounts->firstWhere('id', $accountId);

        return view('auto-replies.index', [
            'accounts' => $accounts,
            'account' => $account,
            'rules' => $account
                ? AutoReply::where('whatsapp_account_id', $account->id)->orderByDesc('is_active')->orderByDesc('priority')->orderBy('id')->get()
                : collect(),
            'allowed' => $account ? $this->planGate->botsAllowed($account) : true,
            'botFlows' => $account ? BotFlow::where('whatsapp_account_id', $account->id)->where('is_active', true)->count() : 0,
        ]);
    }

    public function create(Request $request, CurrentAccount $current): View|RedirectResponse
    {
        $account = $current->accounts()->firstWhere('id', $request->integer('account_id') ?: $current->getOrFirst()?->id);

        if (! $account) {
            return redirect()->route('auto-replies.index')->with('error', 'Connect a WhatsApp number first.');
        }

        $preset = (string) $request->query('preset', '');
        $rule = new AutoReply(array_merge([
            'whatsapp_account_id' => $account->id, 'trigger' => 'first_message', 'match_type' => 'contains', 'gap_hours' => 24,
            'hours_mode' => 'always', 'days' => [1, 2, 3, 4, 5, 6], 'from_time' => '09:00', 'to_time' => '18:00',
            'priority' => 0, 'cooldown_minutes' => 0, 'is_active' => true, 'buttons' => [],
            'reply_text' => "Hi {{contact_name}}! Thanks for messaging {{business_name}}. How can we help you today?",
        ], match ($preset) {
            'away' => ['name' => 'Away message', 'trigger' => 'always', 'hours_mode' => 'outside', 'cooldown_minutes' => 720,
                'reply_text' => "Thanks for your message! We're closed right now (open Mon–Sat 09:00–18:00). We'll reply as soon as we're back."],
            'always' => ['name' => 'Always reply', 'trigger' => 'always', 'cooldown_minutes' => 60,
                'reply_text' => 'Thanks {{contact_name}}, we got your message — an agent will reply shortly.'],
            'returning' => ['name' => 'Welcome back', 'trigger' => 'returning', 'gap_hours' => 24,
                'reply_text' => 'Welcome back {{contact_name}}! 👋 How can we help you this time?'],
            default => ['name' => 'Welcome new customers', 'buttons' => [
                ['id' => 'b1', 'title' => 'Prices', 'reply' => 'Our price list: …'],
                ['id' => 'b2', 'title' => 'Talk to us', 'reply' => 'Sure! An agent will reply in a few minutes.'],
            ]],
        }));

        return view('auto-replies.form', ['rule' => $rule, 'account' => $account]);
    }

    public function store(Request $request, CurrentAccount $current): RedirectResponse
    {
        $account = $current->accounts()->firstWhere('id', $request->integer('whatsapp_account_id'));
        abort_unless($account, 404);

        $rule = AutoReply::create($this->validated($request) + ['whatsapp_account_id' => $account->id]);
        $this->logger->log('auto_reply.created', "Created auto reply {$rule->name}", $rule, accountId: $account->id);

        return redirect()->route('auto-replies.index', ['account_id' => $account->id])->with('success', "Auto reply “{$rule->name}” saved.");
    }

    public function edit(AutoReply $rule): View
    {
        $this->authorizeRule($rule);

        return view('auto-replies.form', ['rule' => $rule, 'account' => $rule->account]);
    }

    public function update(Request $request, AutoReply $rule): RedirectResponse
    {
        $this->authorizeRule($rule);
        $rule->update($this->validated($request));
        $this->logger->log('auto_reply.updated', "Updated auto reply {$rule->name}", $rule, accountId: $rule->whatsapp_account_id);

        return redirect()->route('auto-replies.index', ['account_id' => $rule->whatsapp_account_id])->with('success', 'Saved.');
    }

    public function toggle(AutoReply $rule): RedirectResponse
    {
        $this->authorizeRule($rule);
        $rule->forceFill(['is_active' => ! $rule->is_active])->save();

        return back()->with('success', $rule->is_active ? 'Auto reply switched on.' : 'Auto reply switched off.');
    }

    public function destroy(AutoReply $rule): RedirectResponse
    {
        $this->authorizeRule($rule);
        $rule->delete();
        $this->logger->log('auto_reply.deleted', "Deleted auto reply {$rule->name}", null, accountId: $rule->whatsapp_account_id);

        return redirect()->route('auto-replies.index', ['account_id' => $rule->whatsapp_account_id])->with('success', 'Auto reply deleted.');
    }

    private function authorizeRule(AutoReply $rule): void
    {
        abort_unless(auth()->user()->canAccessAccount($rule->whatsapp_account_id), 404);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $request->merge([
            'is_active' => $request->boolean('is_active'),
            'while_chatting' => $request->boolean('while_chatting'),
            // Drop empty button rows the form keeps for editing.
            'buttons' => collect((array) $request->input('buttons', []))
                ->filter(fn ($b) => is_array($b) && (filled($b['title'] ?? null) || filled($b['reply'] ?? null)))->values()->all(),
        ]);

        $hasButtons = count($request->input('buttons')) > 0;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'trigger' => ['required', Rule::in(array_keys(AutoReply::TRIGGERS))],
            'match_type' => ['required', Rule::in(array_keys(BotFlow::MATCHES))],
            'keywords' => ['nullable', 'required_if:trigger,keyword', 'string', 'max:1000'],
            'gap_hours' => ['nullable', 'integer', 'min:0', 'max:720'],
            'message_types' => ['nullable', 'array'],
            'message_types.*' => [Rule::in(array_keys(BotGraph::MESSAGE_TYPES))],
            'while_chatting' => ['boolean'],
            'hours_mode' => ['required', Rule::in(array_keys(AutoReply::HOURS_MODES))],
            'days' => ['nullable', 'array', 'required_unless:hours_mode,always'],
            'days.*' => ['integer', 'between:1,7'],
            'from_time' => ['nullable', 'required_unless:hours_mode,always', 'date_format:H:i'],
            'to_time' => ['nullable', 'required_unless:hours_mode,always', 'date_format:H:i'],
            'cooldown_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['boolean'],
            'reply_text' => ['required', 'string', 'max:'.($hasButtons ? 1024 : 4096)],
            'buttons' => ['array', 'max:'.AutoReply::MAX_BUTTONS],
            'buttons.*.id' => ['nullable', 'string', 'regex:/^[a-z0-9]{1,12}$/'],
            'buttons.*.title' => ['required', 'string', 'max:20', 'distinct:ignore_case'],
            'buttons.*.reply' => ['required', 'string', 'max:1024'],
        ], [
            'keywords.required_if' => 'Add at least one keyword.',
            'days.required_unless' => 'Pick at least one day.',
            'reply_text.max' => $hasButtons ? 'With quick action buttons the message can have at most 1024 characters.' : 'The message can have at most 4096 characters.',
            'buttons.max' => 'WhatsApp allows at most 3 quick action buttons.',
            'buttons.*.title.required' => 'Every button needs a title.',
            'buttons.*.title.max' => 'Button titles can have at most 20 characters.',
            'buttons.*.title.distinct' => 'Two buttons have the same title.',
            'buttons.*.reply.required' => 'Write the answer each button sends.',
        ]);

        $used = [];
        $buttons = array_map(function (array $b) use (&$used) {
            $id = (string) ($b['id'] ?? '');
            while ($id === '' || in_array($id, $used, true)) {
                $id = Str::lower(Str::random(6));
            }
            $used[] = $id;

            return ['id' => $id, 'title' => trim($b['title']), 'reply' => trim($b['reply'])];
        }, $data['buttons'] ?? []);

        return [
            'name' => $data['name'],
            'trigger' => $data['trigger'],
            'match_type' => $data['match_type'],
            'keywords' => $data['trigger'] === 'keyword' ? $data['keywords'] : null,
            'gap_hours' => (int) ($data['gap_hours'] ?? 0),
            'message_types' => array_values(array_unique($data['message_types'] ?? [])) ?: null,
            'while_chatting' => $data['while_chatting'],
            'hours_mode' => $data['hours_mode'],
            'days' => $data['hours_mode'] === 'always' ? null : array_values(array_unique(array_map('intval', $data['days'] ?? []))),
            'from_time' => $data['hours_mode'] === 'always' ? null : $data['from_time'],
            'to_time' => $data['hours_mode'] === 'always' ? null : $data['to_time'],
            'cooldown_minutes' => (int) ($data['cooldown_minutes'] ?? 0),
            'priority' => (int) ($data['priority'] ?? 0),
            'is_active' => $data['is_active'],
            'reply_text' => trim($data['reply_text']),
            'buttons' => $buttons ?: null,
        ];
    }
}
