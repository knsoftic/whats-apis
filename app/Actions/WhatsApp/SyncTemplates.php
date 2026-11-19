<?php

namespace App\Actions\WhatsApp;

use App\Models\MessageTemplate;
use App\Models\WhatsAppAccount;
use App\Services\ActivityLogger;
use App\Services\WhatsApp\WhatsAppCloudApiService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pulls all message templates of the account's WABA from Meta and mirrors
 * them locally (insert / update / remove the ones deleted in Meta).
 */
class SyncTemplates
{
    public function __construct(private readonly ActivityLogger $logger) {}

    /**
     * @return array{total:int, approved:int, removed:int}
     */
    public function run(WhatsAppAccount $account): array
    {
        $remote = WhatsAppCloudApiService::for($account)->getTemplates();
        $now = now();
        $keep = [];

        DB::transaction(function () use ($account, $remote, $now, &$keep) {
            foreach ($remote as $t) {
                if (empty($t['name']) || empty($t['language'])) {
                    continue;
                }

                $template = MessageTemplate::updateOrCreate(
                    ['whatsapp_account_id' => $account->id, 'name' => $t['name'], 'language' => $t['language']],
                    [
                        'meta_template_id' => isset($t['id']) ? (string) $t['id'] : null,
                        'category' => $t['category'] ?? null,
                        'status' => strtoupper((string) ($t['status'] ?? '')),
                        'parameter_format' => strtoupper((string) ($t['parameter_format'] ?? 'POSITIONAL')),
                        'components' => $t['components'] ?? [],
                        'rejected_reason' => ($t['rejected_reason'] ?? 'NONE') !== 'NONE' ? Str::limit((string) $t['rejected_reason'], 250, '') : null,
                        'synced_at' => $now,
                    ],
                );

                $keep[] = $template->id;
            }
        });

        $removed = MessageTemplate::where('whatsapp_account_id', $account->id)->whereNotIn('id', $keep)->delete();

        $approved = MessageTemplate::where('whatsapp_account_id', $account->id)->where('status', MessageTemplate::APPROVED)->count();

        $this->logger->log('template.synced', "Synced templates for {$account->name}", properties: [
            'total' => count($keep), 'approved' => $approved, 'removed' => $removed,
        ], accountId: $account->id);

        return ['total' => count($keep), 'approved' => $approved, 'removed' => $removed];
    }
}
