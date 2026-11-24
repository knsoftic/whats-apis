<?php

namespace App\Support;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

/**
 * Inbox counters for the sidebar badge, the chat filter tabs and the number
 * switcher: unread chats, unread messages, chats waiting for a reply, open
 * and closed chats — per WhatsApp number and in total (one grouped query).
 */
class ChatCounts
{
    public const EMPTY = ['all' => 0, 'unread' => 0, 'messages' => 0, 'waiting' => 0, 'open' => 0, 'closed' => 0];

    /**
     * @param  array<int>  $accountIds
     * @return array{total: array<string,int>, accounts: array<int, array<string,int>>}
     */
    public static function forAccounts(array $accountIds): array
    {
        $accounts = [];

        if ($accountIds) {
            $rows = Conversation::query()
                ->whereIn('whatsapp_account_id', $accountIds)
                ->selectRaw(
                    'whatsapp_account_id,
                     COUNT(*) AS c_all,
                     SUM(CASE WHEN unread_count > 0 THEN 1 ELSE 0 END) AS c_unread,
                     SUM(unread_count) AS c_messages,
                     SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS c_open,
                     SUM(CASE WHEN status = ? AND last_message_direction = ? THEN 1 ELSE 0 END) AS c_waiting',
                    [Conversation::STATUS_OPEN, Conversation::STATUS_OPEN, Message::IN]
                )
                ->groupBy('whatsapp_account_id')
                ->toBase()
                ->get();

            foreach ($rows as $r) {
                $all = (int) $r->c_all;
                $open = (int) $r->c_open;
                $accounts[(int) $r->whatsapp_account_id] = [
                    'all' => $all,
                    'unread' => (int) $r->c_unread,
                    'messages' => (int) $r->c_messages,
                    'waiting' => (int) $r->c_waiting,
                    'open' => $open,
                    'closed' => $all - $open,
                ];
            }
        }

        $total = self::EMPTY;
        foreach ($accounts as $c) {
            foreach ($total as $k => $v) {
                $total[$k] = $v + $c[$k];
            }
        }

        return ['total' => $total, 'accounts' => $accounts];
    }

    /** Counters across every WhatsApp number this user can open in the inbox. */
    public static function forUser(?User $user): array
    {
        if (! $user || ! $user->can(Permissions::CHATS_VIEW)) {
            return ['total' => self::EMPTY, 'accounts' => []];
        }

        $accounts = $user->is(auth()->user())
            ? app(CurrentAccount::class)->accounts()          // already loaded for this request
            : $user->accessibleAccounts()->get();

        return self::forAccounts($accounts->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    /** Counters of one number (zeros when it has no chats yet). */
    public static function forAccount(int $accountId): array
    {
        return self::forAccounts([$accountId])['accounts'][$accountId] ?? self::EMPTY;
    }
}
