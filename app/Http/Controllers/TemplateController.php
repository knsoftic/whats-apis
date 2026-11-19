<?php

namespace App\Http\Controllers;

use App\Actions\WhatsApp\SyncTemplates;
use App\Models\MessageTemplate;
use App\Services\WhatsApp\MessageSender;
use App\Services\WhatsApp\WhatsAppApiException;
use App\Support\CurrentAccount;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TemplateController extends Controller
{
    public function index(Request $request, CurrentAccount $current): View
    {
        $filters = $request->validate([
            'account_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:20'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $accounts = $current->accounts();
        $account = isset($filters['account_id'])
            ? $accounts->firstWhere('id', (int) $filters['account_id'])
            : $current->getOrFirst();

        abort_if(isset($filters['account_id']) && ! $account, 404);

        $templates = $account
            ? MessageTemplate::where('whatsapp_account_id', $account->id)
                ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', strtoupper($s)))
                ->when($filters['q'] ?? null, fn ($q, $term) => $q->where('name', 'like', '%'.addcslashes($term, '%_\\').'%'))
                ->orderByRaw("CASE WHEN status = 'APPROVED' THEN 0 ELSE 1 END")
                ->orderBy('name')
                ->paginate(24)
                ->withQueryString()
            : null;

        return view('templates.index', compact('accounts', 'account', 'templates', 'filters'));
    }

    public function show(Request $request, MessageTemplate $template): View
    {
        abort_unless($request->user()->can(Permissions::TEMPLATES_VIEW) && $request->user()->canAccessAccount($template->whatsapp_account_id), 404);

        return view('templates.show', ['template' => $template->load('account')]);
    }

    public function sync(Request $request, SyncTemplates $sync): RedirectResponse
    {
        $data = $request->validate(['account_id' => ['required', 'integer']]);
        $account = $request->user()->accessibleAccounts()->findOrFail($data['account_id']);

        try {
            $result = $sync->run($account);
        } catch (WhatsAppApiException $e) {
            return back()->with('error', 'Template sync failed: '.$e->userMessage());
        }

        return redirect()->route('templates.index', ['account_id' => $account->id])->with(
            'success',
            "Synced {$result['total']} templates ({$result['approved']} approved".($result['removed'] ? ", {$result['removed']} removed" : '').').',
        );
    }

    /**
     * Send a template to any phone number from the template page.
     */
    public function send(Request $request, MessageTemplate $template, MessageSender $sender): RedirectResponse
    {
        abort_unless($request->user()->can(Permissions::TEMPLATES_SEND) && $request->user()->canAccessAccount($template->whatsapp_account_id), 404);

        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:/^\+?[0-9 ()\-]{8,20}$/'],
            'name' => ['nullable', 'string', 'max:120'],
            'variables' => ['nullable', 'array'],
        ], ['phone.regex' => 'Enter the full number with country code, e.g. +91 98765 43210.']);

        $digits = preg_replace('/\D+/', '', $data['phone']);

        if (strlen($digits) < 8 || strlen($digits) > 15) {
            return back()->withInput()->withErrors(['phone' => 'Phone number must have 8–15 digits including country code.']);
        }

        $conversation = $sender->conversationFor($template->account, $digits, $data['name'] ?? null);
        $message = $sender->sendTemplate($conversation, $template, $data['variables'] ?? [], $request->user());
        $message->refresh();

        return redirect()->route('chat.index', ['conversation' => $conversation->id])->with(
            $message->status === 'failed' ? 'error' : 'success',
            $message->status === 'failed' ? 'Template failed: '.$message->error_message : 'Template message queued for '.$conversation->contact->phone.'.',
        );
    }
}
