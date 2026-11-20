<?php

namespace App\Http\Controllers;

use App\Http\Resources\ConversationResource;
use App\Models\BotFlow;
use App\Models\Conversation;
use App\Models\WhatsAppAccount;
use App\Services\ActivityLogger;
use App\Services\PlanGate;
use App\Support\BotGraph;
use App\Support\CurrentAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BotFlowController extends Controller
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

        $flows = $accountId
            ? BotFlow::where('whatsapp_account_id', $accountId)->orderByDesc('is_active')->orderByDesc('priority')->orderBy('name')->get()
            : collect();

        $account = $accounts->firstWhere('id', $accountId);

        return view('bots.index', [
            'accounts' => $accounts,
            'account' => $account,
            'flows' => $flows,
            'botsAllowed' => $account ? $this->planGate->botsAllowed($account) : true,
        ]);
    }

    public function create(Request $request, CurrentAccount $current): View|RedirectResponse
    {
        $accounts = $current->accounts();
        $accountId = $request->integer('account_id') ?: $current->getOrFirst()?->id;
        $account = $accounts->firstWhere('id', $accountId);

        if (! $account) {
            return redirect()->route('bots.index')->with('error', 'Connect a WhatsApp number first.');
        }

        try {
            $this->planGate->ensureCanCreateBotFlow($account);
        } catch (\App\Exceptions\PlanLimitException $e) {
            return redirect()->route('bots.index', ['account_id' => $account->id])->with('error', $e->getMessage());
        }

        return view('bots.form', $this->formData(new BotFlow([
            'whatsapp_account_id' => $account->id, 'trigger_type' => 'keyword', 'match_type' => 'contains',
            'is_active' => true, 'priority' => 0, 'cooldown_minutes' => 0,
            'steps' => [], 'graph' => BotGraph::starter(),
        ]), $accounts));
    }

    public function store(Request $request, CurrentAccount $current): RedirectResponse|JsonResponse
    {
        $account = $current->accounts()->firstWhere('id', $request->integer('whatsapp_account_id'));
        abort_unless($account, 404);

        $this->planGate->ensureCanCreateBotFlow($account);

        $flow = BotFlow::create($this->validated($request, $account));
        $this->logger->log('bot.created', "Created bot flow {$flow->name}", $flow, accountId: $account->id);

        if ($request->expectsJson()) {
            return response()->json(['message' => "Bot flow “{$flow->name}” saved.", 'id' => $flow->id, 'redirect' => route('bots.edit', $flow)], 201);
        }

        return redirect()->route('bots.index', ['account_id' => $account->id])->with('success', "Bot flow “{$flow->name}” saved.");
    }

    public function edit(BotFlow $flow, CurrentAccount $current): View
    {
        $this->authorizeFlow($flow);

        return view('bots.form', $this->formData($flow, $current->accounts()));
    }

    public function update(Request $request, BotFlow $flow): RedirectResponse|JsonResponse
    {
        $this->authorizeFlow($flow);

        $flow->update($this->validated($request, $flow->account, $flow));
        $this->logger->log('bot.updated', "Updated bot flow {$flow->name}", $flow, accountId: $flow->whatsapp_account_id);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Saved.', 'id' => $flow->id]);
        }

        return redirect()->route('bots.index', ['account_id' => $flow->whatsapp_account_id])->with('success', "Bot flow “{$flow->name}” updated.");
    }

    public function destroy(BotFlow $flow): RedirectResponse
    {
        $this->authorizeFlow($flow);

        $accountId = $flow->whatsapp_account_id;
        $name = $flow->name;
        $flow->delete();
        $this->logger->log('bot.deleted', "Deleted bot flow {$name}", accountId: $accountId);

        return redirect()->route('bots.index', ['account_id' => $accountId])->with('success', "Bot flow “{$name}” deleted.");
    }

    public function toggle(BotFlow $flow): RedirectResponse
    {
        $this->authorizeFlow($flow);
        $flow->forceFill(['is_active' => ! $flow->is_active])->save();

        return back()->with('success', $flow->is_active ? 'Bot flow turned on.' : 'Bot flow turned off.');
    }

    /**
     * Pause / resume bots in one chat (chat header menu).
     */
    public function conversationBot(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $conversation);

        $data = $request->validate(['action' => ['required', 'in:pause,resume']]);
        $conversation->forceFill([
            'bot_paused_until' => $data['action'] === 'pause' ? now()->addHours((int) config('whatsapp.bot.handoff_hours', 24)) : null,
        ])->save();

        return response()->json(['conversation' => (new ConversationResource($conversation->load('contact')))->resolve()]);
    }

    // -------------------------------------------------------------------------

    private function authorizeFlow(BotFlow $flow): void
    {
        abort_unless(auth()->user()->canAccessAccount($flow->whatsapp_account_id), 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(BotFlow $flow, $accounts): array
    {
        // Menu options can point to any flow of the same number.
        $targets = BotFlow::whereIn('whatsapp_account_id', $accounts->pluck('id'))
            ->orderBy('name')->get(['id', 'name', 'whatsapp_account_id', 'trigger_type'])
            ->groupBy('whatsapp_account_id')
            ->map(fn ($group) => $group->map(fn ($f) => ['id' => $f->id, 'name' => $f->name, 'trigger' => $f->trigger_type])->values());

        $old = old('graph') ? json_decode((string) old('graph'), true) : null;

        return [
            'flow' => $flow,
            'accounts' => $accounts,
            'targets' => $targets,
            'graph' => is_array($old) ? $old : ($flow->exists || $flow->graph ? $flow->flowGraph() : BotGraph::starter()),
            // Tag names per number (Condition → contact tag).
            'tags' => \App\Models\Tag::whereIn('whatsapp_account_id', $accounts->pluck('id'))->orderBy('name')->get(['name', 'whatsapp_account_id'])
                ->groupBy('whatsapp_account_id')->map(fn ($g) => $g->pluck('name')->values()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, WhatsAppAccount $account, ?BotFlow $flow = null): array
    {
        $request->merge(['is_active' => $request->boolean('is_active')]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'trigger_type' => ['required', Rule::in(array_keys(BotFlow::TRIGGERS))],
            'match_type' => ['required', Rule::in(array_keys(BotFlow::MATCHES))],
            'keywords' => ['nullable', 'required_if:trigger_type,keyword', 'string', 'max:1000'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'cooldown_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'is_active' => ['boolean'],
            'graph' => ['required_without:steps', 'nullable', 'json', 'max:200000'],
            'steps' => ['required_without:graph', 'nullable', 'json'],
        ], ['keywords.required_if' => 'Add at least one keyword for a keyword flow.']);

        if (filled($data['graph'] ?? null)) {
            $graph = BotGraph::validate(json_decode($data['graph'], true), $account, $flow);
            $steps = [];
        } else {
            $graph = null;
            $steps = $this->validateSteps(json_decode($data['steps'], true), $account, $flow);
        }

        return [
            'whatsapp_account_id' => $account->id,
            'name' => $data['name'],
            'trigger_type' => $data['trigger_type'],
            'match_type' => $data['match_type'],
            'keywords' => $data['trigger_type'] === 'keyword' ? $data['keywords'] : null,
            'priority' => (int) ($data['priority'] ?? 0),
            'cooldown_minutes' => (int) ($data['cooldown_minutes'] ?? 0),
            'is_active' => $data['is_active'],
            'steps' => $steps,
            'graph' => $graph,
        ];
    }

    /**
     * WhatsApp limits: text 4096, interactive body 1024, button title 20,
     * list row title 24, list row description 72, list button 20, footer 60.
     *
     * @return array<int, array<string, mixed>>
     */
    private function validateSteps(mixed $steps, WhatsAppAccount $account, ?BotFlow $flow): array
    {
        $errors = [];
        $max = (int) config('whatsapp.bot.max_steps', 8);

        if (! is_array($steps) || $steps === [] || count($steps) > $max) {
            throw ValidationException::withMessages(['steps' => "A flow needs 1 to {$max} steps."]);
        }

        $flowIds = BotFlow::where('whatsapp_account_id', $account->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $clean = [];

        foreach (array_values($steps) as $i => $step) {
            $n = $i + 1;
            $type = $step['type'] ?? null;

            switch ($type) {
                case 'text':
                    $text = trim((string) ($step['text'] ?? ''));
                    if ($text === '' || mb_strlen($text) > 4096) {
                        $errors["steps.{$i}"] = "Step {$n}: message text is required (max 4096 characters).";
                    }
                    $clean[] = ['type' => 'text', 'text' => $text];
                    break;

                case 'menu':
                    $text = trim((string) ($step['text'] ?? ''));
                    $options = array_values(array_filter($step['options'] ?? [], fn ($o) => trim((string) ($o['title'] ?? '')) !== '' || ! empty($o['flow_id'])));
                    $titleMax = count($options) <= 3 ? 20 : 24;

                    if ($text === '' || mb_strlen($text) > 1024) {
                        $errors["steps.{$i}"] = "Step {$n}: menu message is required (max 1024 characters).";
                    } elseif (count($options) < 1 || count($options) > 10) {
                        $errors["steps.{$i}"] = "Step {$n}: a menu needs 1 to 10 options.";
                    } elseif (mb_strlen((string) ($step['footer'] ?? '')) > 60 || mb_strlen((string) ($step['button_label'] ?? '')) > 20) {
                        $errors["steps.{$i}"] = "Step {$n}: footer max 60, list button label max 20 characters.";
                    } else {
                        foreach ($options as $j => $o) {
                            $title = trim((string) ($o['title'] ?? ''));
                            $target = (int) ($o['flow_id'] ?? 0);

                            if ($title === '' || mb_strlen($title) > $titleMax) {
                                $errors["steps.{$i}"] = "Step {$n}, option ".($j + 1).": title is required (max {$titleMax} characters".($titleMax === 20 ? ' for buttons' : ' for list rows').').';
                                break;
                            }

                            if (! in_array($target, $flowIds, true)) {
                                $errors["steps.{$i}"] = "Step {$n}, option ".($j + 1).': choose which flow this option opens (a flow of the same WhatsApp number).';
                                break;
                            }

                            if (mb_strlen((string) ($o['description'] ?? '')) > 72) {
                                $errors["steps.{$i}"] = "Step {$n}, option ".($j + 1).': description max 72 characters.';
                                break;
                            }
                        }
                    }

                    $clean[] = [
                        'type' => 'menu',
                        'text' => $text,
                        'footer' => trim((string) ($step['footer'] ?? '')) ?: null,
                        'button_label' => trim((string) ($step['button_label'] ?? '')) ?: null,
                        'options' => array_map(fn ($o) => [
                            'title' => trim((string) ($o['title'] ?? '')),
                            'description' => trim((string) ($o['description'] ?? '')) ?: null,
                            'flow_id' => (int) ($o['flow_id'] ?? 0),
                        ], $options),
                    ];
                    break;

                case 'image':
                case 'document':
                    $url = trim((string) ($step['url'] ?? ''));
                    if (! filter_var($url, FILTER_VALIDATE_URL) || ! str_starts_with(strtolower($url), 'https://') || mb_strlen($url) > 2000) {
                        $errors["steps.{$i}"] = "Step {$n}: enter a public HTTPS link to the ".($type === 'image' ? 'JPG/PNG image' : 'document').'.';
                    }
                    $clean[] = array_filter([
                        'type' => $type,
                        'url' => $url,
                        'caption' => trim((string) ($step['caption'] ?? '')) ?: null,
                        'filename' => $type === 'document' ? (trim((string) ($step['filename'] ?? '')) ?: null) : null,
                    ], fn ($v) => $v !== null);
                    break;

                case 'handoff':
                    $text = trim((string) ($step['text'] ?? ''));
                    if (mb_strlen($text) > 1024) {
                        $errors["steps.{$i}"] = "Step {$n}: handoff message max 1024 characters.";
                    }
                    $clean[] = ['type' => 'handoff', 'text' => $text ?: null];
                    break;

                default:
                    $errors["steps.{$i}"] = "Step {$n}: unknown step type.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }
}
