<?php

use App\Actions\WhatsApp\CheckAccountConnection;
use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use App\Models\WhatsAppAccount;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console commands
|--------------------------------------------------------------------------
*/

Artisan::command('whatsapp:check-accounts {--id= : Only this account id}', function (CheckAccountConnection $checker) {
    $accounts = WhatsAppAccount::query()
        ->when($this->option('id'), fn ($q, $id) => $q->whereKey($id), fn ($q) => $q->where('is_active', true))
        ->get();

    foreach ($accounts as $account) {
        $result = $checker->run($account);
        $this->line(sprintf('%-30s %s  %s', $account->name, $result->ok ? '<info>OK</info>    ' : '<error>FAILED</error>', $result->message));
    }

    $this->info("Checked {$accounts->count()} account(s).");
})->purpose('Re-validate WhatsApp Cloud API credentials of all active accounts');

Artisan::command('whatsapp:retry-webhooks {--hours=24}', function () {
    $events = WebhookEvent::where('status', WebhookEvent::FAILED)
        ->where('created_at', '>=', now()->subHours((int) $this->option('hours')))
        ->get();

    foreach ($events as $event) {
        $event->forceFill(['status' => WebhookEvent::RECEIVED, 'error' => null])->save();
        ProcessWebhookEvent::dispatch($event->id)->onQueue(config('whatsapp.queue'));
    }

    $this->info("Re-queued {$events->count()} failed webhook event(s).");
})->purpose('Re-process webhook events that failed');

/*
|--------------------------------------------------------------------------
| Scheduler — run `php artisan schedule:run` every minute (cron / Task Scheduler)
|--------------------------------------------------------------------------
*/

// Detect expired / revoked tokens early (does not send any message).
Schedule::command('whatsapp:check-accounts')->hourly()->withoutOverlapping();

// Delete old processed webhook payloads (WHATSAPP_WEBHOOK_RETENTION_DAYS).
Schedule::command('model:prune', ['--model' => [WebhookEvent::class]])->daily();

Schedule::command('queue:prune-failed --hours=720')->daily();
