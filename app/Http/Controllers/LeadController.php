<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Support\CurrentAccount;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Leads sheet: every new number's first message — view, filter, export for campaigns. */
class LeadController extends Controller
{
    public function index(Request $request, CurrentAccount $current): View
    {
        $accounts = $current->accounts();
        $filters = $this->filters($request, $accounts->pluck('id')->all());

        $query = Lead::query()->with('account:id,name')
            ->whereIn('whatsapp_account_id', $accounts->pluck('id'))
            ->filtered($filters);

        return view('leads.index', [
            'leads' => (clone $query)->latest('first_message_at')->paginate(25)->withQueryString(),
            'total' => (clone $query)->count(),
            'today' => (clone $query)->where('first_message_at', '>=', now()->startOfDay())->count(),
            'week' => (clone $query)->where('first_message_at', '>=', now()->subDays(7)->startOfDay())->count(),
            'accounts' => $accounts,
            'filters' => $filters,
        ]);
    }

    /** CSV that opens in Excel / Google Sheets — same filters as the page. */
    public function export(Request $request, CurrentAccount $current): StreamedResponse
    {
        $accounts = $current->accounts();
        $filters = $this->filters($request, $accounts->pluck('id')->all());
        $query = Lead::query()->with('account:id,name')->whereIn('whatsapp_account_id', $accounts->pluck('id'))->filtered($filters);

        // No spreadsheet formulas — but a plain phone number like "+92 300 …" stays as it is.
        $safe = fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) && ! preg_match('/^\+?[\d\s()\-]+$/', $v) ? "'".$v : $v;

        return response()->streamDownload(function () use ($query, $safe) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($out, ['name', 'phone', 'whatsapp_number', 'first_message', 'first_message_at', 'whatsapp_account']);

            $query->orderBy('first_message_at')->chunk(1000, function ($leads) use ($out, $safe) {
                foreach ($leads as $lead) {
                    fputcsv($out, array_map($safe, [
                        $lead->profile_name,
                        $lead->phone,
                        $lead->wa_id, // digits only — ready for bulk / campaign tools
                        $lead->first_message,
                        $lead->first_message_at?->toDateTimeString(),
                        $lead->account?->name,
                    ]));
                }
            });

            fclose($out);
        }, 'leads-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  list<int>  $allowedAccountIds
     * @return array{account_id: ?int, from: ?string, to: ?string, q: ?string}
     */
    private function filters(Request $request, array $allowedAccountIds): array
    {
        $data = $request->validate([
            'account_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $accountId = isset($data['account_id']) && in_array((int) $data['account_id'], $allowedAccountIds, true) ? (int) $data['account_id'] : null;

        return ['account_id' => $accountId, 'from' => $data['from'] ?? null, 'to' => $data['to'] ?? null, 'q' => trim((string) ($data['q'] ?? '')) ?: null];
    }
}
