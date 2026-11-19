<?php

namespace App\Http\Controllers;

use App\Http\Requests\ContactRequest;
use App\Models\Contact;
use App\Models\ContactNote;
use App\Models\Tag;
use App\Services\ActivityLogger;
use App\Services\ContactService;
use App\Support\CurrentAccount;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContactController extends Controller
{
    public function __construct(
        private readonly ContactService $contacts,
        private readonly ActivityLogger $logger,
    ) {}

    public function index(Request $request, CurrentAccount $current): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'account_id' => ['nullable', 'integer'],
            'tag' => ['nullable', 'integer'],
            'opt_in' => ['nullable', 'in:unknown,opted_in,opted_out'],
        ]);

        $accountIds = $this->scopedAccountIds($request, $current, $filters['account_id'] ?? null);

        $contacts = $this->filteredQuery($accountIds, $filters)
            ->with(['tags', 'account:id,name', 'conversation:id,contact_id,last_message_at,unread_count'])
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('contacts.index', [
            'contacts' => $contacts,
            'filters' => $filters,
            'accounts' => $current->accounts(),
            'tags' => Tag::whereIn('whatsapp_account_id', $accountIds)->orderBy('name')->get(['id', 'name', 'whatsapp_account_id']),
        ]);
    }

    public function create(CurrentAccount $current): View
    {
        abort_unless(auth()->user()->can(Permissions::CONTACTS_MANAGE), 403);

        return view('contacts.form', [
            'contact' => new Contact(['opt_in_status' => Contact::OPT_UNKNOWN, 'whatsapp_account_id' => $current->getOrFirst()?->id]),
            'accounts' => $current->accounts(),
            'tagString' => '',
        ]);
    }

    public function store(ContactRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $contact = DB::transaction(function () use ($data) {
            $contact = new Contact([
                'whatsapp_account_id' => $data['whatsapp_account_id'],
                'wa_id' => $data['wa_id'],
                'phone' => Contact::formatPhone($data['wa_id']),
                'name' => $data['name'],
                'email' => $data['email'],
            ]);
            $this->contacts->applyOptIn($contact, $data['opt_in_status'], $data['opt_in_source'] ?? 'manual');
            $contact->save();
            $this->contacts->syncTags($contact, $data['tags'] ?? null);

            return $contact;
        });

        $this->logger->log('contact.created', "Added contact {$contact->phone}", $contact, accountId: $contact->whatsapp_account_id);

        return redirect()->route('contacts.show', $contact)->with('success', 'Contact added.');
    }

    public function show(Request $request, Contact $contact): View
    {
        $this->authorize('view', $contact);

        $contact->load(['tags', 'account', 'notes.user:id,name', 'conversation']);

        $messages = $contact->messages()
            ->with('attachments')
            ->where('type', '!=', 'reaction')
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return view('contacts.show', compact('contact', 'messages'));
    }

    public function edit(Contact $contact): View
    {
        $this->authorize('update', $contact);

        return view('contacts.form', [
            'contact' => $contact,
            'accounts' => collect([$contact->account]),
            'tagString' => $contact->tags()->pluck('name')->implode(', '),
        ]);
    }

    public function update(ContactRequest $request, Contact $contact): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($contact, $data) {
            $contact->fill([
                'wa_id' => $data['wa_id'],
                'phone' => Contact::formatPhone($data['wa_id']),
                'name' => $data['name'],
                'email' => $data['email'],
            ]);
            $this->contacts->applyOptIn($contact, $data['opt_in_status'], $data['opt_in_source'] ?? 'manual');
            $contact->save();
            $this->contacts->syncTags($contact, $data['tags'] ?? null);
        });

        $this->logger->log('contact.updated', "Updated contact {$contact->phone}", $contact, [
            'opt_in_status' => $contact->opt_in_status,
        ], $contact->whatsapp_account_id);

        return redirect()->route('contacts.show', $contact)->with('success', 'Contact updated.');
    }

    public function destroy(Request $request, Contact $contact): RedirectResponse
    {
        $this->authorize('delete', $contact);

        $phone = $contact->phone;
        $accountId = $contact->whatsapp_account_id;
        $contact->delete();

        $this->logger->log('contact.deleted', "Deleted contact {$phone} and its conversation", properties: ['phone' => $phone], accountId: $accountId);

        return redirect()->route('contacts.index')->with('success', 'Contact and conversation history deleted.');
    }

    public function storeNote(Request $request, Contact $contact): RedirectResponse
    {
        $this->authorize('update', $contact);

        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        $contact->notes()->create(['user_id' => $request->user()->id, 'body' => $data['body']]);

        return back()->with('success', 'Note added.');
    }

    public function destroyNote(Contact $contact, ContactNote $note): RedirectResponse
    {
        $this->authorize('update', $contact);
        abort_unless($note->contact_id === $contact->id, 404);

        $note->delete();

        return back()->with('success', 'Note deleted.');
    }

    public function importForm(CurrentAccount $current): View
    {
        return view('contacts.import', ['accounts' => $current->accounts(), 'selected' => $current->getOrFirst()?->id]);
    }

    public function import(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'whatsapp_account_id' => ['required', 'integer'],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $account = $request->user()->accessibleAccounts()->findOrFail($data['whatsapp_account_id']);
        $result = $this->contacts->import($account, $request->file('file'));

        $this->logger->log('contact.imported', "Imported contacts into {$account->name}", properties: [
            'created' => $result['created'], 'updated' => $result['updated'], 'skipped' => $result['skipped'],
        ], accountId: $account->id);

        return redirect()->route('contacts.import.form')->with('importResult', $result)->with(
            $result['created'] + $result['updated'] > 0 ? 'success' : 'warning',
            "Import finished: {$result['created']} created, {$result['updated']} updated, {$result['skipped']} skipped.",
        );
    }

    public function export(Request $request, CurrentAccount $current): StreamedResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'account_id' => ['nullable', 'integer'],
            'tag' => ['nullable', 'integer'],
            'opt_in' => ['nullable', 'in:unknown,opted_in,opted_out'],
        ]);

        $accountIds = $this->scopedAccountIds($request, $current, $filters['account_id'] ?? null);
        $query = $this->filteredQuery($accountIds, $filters);

        $this->logger->log('contact.exported', 'Exported '.(clone $query)->count().' contacts to CSV', properties: $filters);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            $this->contacts->exportCsv($query, $out);
            fclose($out);
        }, 'contacts-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array<int, int>
     */
    private function scopedAccountIds(Request $request, CurrentAccount $current, ?int $accountId): array
    {
        $allowed = $current->accounts()->pluck('id')->all();

        if ($accountId) {
            abort_unless(in_array($accountId, $allowed, true), 404);

            return [$accountId];
        }

        return $current->scopeIds();
    }

    /**
     * @param  array<int, int>  $accountIds
     * @param  array<string, mixed>  $filters
     * @return Builder<Contact>
     */
    private function filteredQuery(array $accountIds, array $filters): Builder
    {
        return Contact::query()
            ->whereIn('whatsapp_account_id', $accountIds)
            ->search($filters['q'] ?? null)
            ->when($filters['tag'] ?? null, fn (Builder $q, $tag) => $q->whereHas('tags', fn (Builder $t) => $t->whereKey($tag)))
            ->when($filters['opt_in'] ?? null, fn (Builder $q, $opt) => $q->where('opt_in_status', $opt));
    }
}
