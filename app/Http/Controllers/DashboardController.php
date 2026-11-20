<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Support\CurrentAccount;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(CurrentAccount $current): View
    {
        $ids = $current->scopeIds();
        $accounts = $current->get() ? collect([$current->get()]) : $current->accounts();

        $messages = fn () => Message::query()->whereIn('whatsapp_account_id', $ids);

        $stats = [
            'numbers_total' => $accounts->count(),
            'numbers_operational' => $accounts->filter->isOperational()->count(),
            'numbers_api_ok' => $accounts->filter->isApiConnected()->count(),
            'contacts' => Contact::query()->whereIn('whatsapp_account_id', $ids)->count(),
            'sent' => $messages()->where('direction', Message::OUT)
                ->whereIn('status', [Message::STATUS_SENT, Message::STATUS_DELIVERED, Message::STATUS_READ])->count(),
            'received' => $messages()->where('direction', Message::IN)->count(),
            'failed' => $messages()->where('direction', Message::OUT)->where('status', Message::STATUS_FAILED)->count(),
            'unread' => Conversation::query()->whereIn('whatsapp_account_id', $ids)->where('unread_count', '>', 0)->count(),
        ];

        // Last 7 days, sent vs received.
        $from = now()->subDays(6)->startOfDay();
        $rows = $messages()
            ->where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) as day, direction, COUNT(*) as total')
            ->groupBy('day', 'direction')
            ->get();

        $chart = collect(range(0, 6))->map(function (int $i) use ($from, $rows) {
            $day = $from->copy()->addDays($i)->toDateString();

            return [
                'label' => Carbon::parse($day)->format('D'),
                'date' => $day,
                'out' => (int) $rows->first(fn ($r) => (string) $r->day === $day && $r->direction === Message::OUT)?->total,
                'in' => (int) $rows->first(fn ($r) => (string) $r->day === $day && $r->direction === Message::IN)?->total,
            ];
        });

        $recent = Conversation::query()
            ->with(['contact', 'account'])
            ->whereIn('whatsapp_account_id', $ids)
            ->whereNotNull('last_message_at')
            ->orderByDesc('last_message_at')
            ->limit(8)
            ->get();

        return view('dashboard', [
            'planUsage' => app(\App\Services\PlanGate::class)->usage(auth()->user()),
            'stats' => $stats,
            'chart' => $chart,
            'chartMax' => max(1, $chart->max(fn ($d) => max($d['in'], $d['out']))),
            'recent' => $recent,
            'accounts' => $accounts,
            'currentAccount' => $current->get(),
        ]);
    }
}
