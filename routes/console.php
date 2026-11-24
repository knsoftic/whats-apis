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

Artisan::command('whatsapp:send-followups', function (App\Services\WhatsApp\FollowUpService $followUps) {
    $sent = $followUps->sendDue();
    $this->info("Queued {$sent} follow-up message(s).");
})->purpose('Send the automatic follow-up to chats whose 24-hour window is about to close');

Artisan::command('whatsapp:webhooks', function () {
    $accounts = WhatsAppAccount::orderBy('id')->get();

    if ($accounts->isEmpty()) {
        $this->warn('No WhatsApp accounts are saved yet — add one in the dashboard and click “Save & validate” first.');

        return;
    }

    foreach ($accounts as $account) {
        $this->line("<info>#{$account->id} {$account->name}</info>  (Phone Number ID {$account->phone_number_id}, webhook: {$account->webhook_status})");

        if ($account->isEmbeddedSignup()) {
            $this->line('   Connected with Embedded Signup → uses the shared platform URL: '.route('webhooks.whatsapp.platform'));
        } else {
            $this->line('   Callback URL : '.$account->webhookUrl());
            $this->line('   Verify token : '.$account->webhook_verify_token);
        }

        $this->newLine();
    }
})->purpose('Show each WhatsApp account\'s webhook Callback URL and verify token (paste them into Meta)');

Artisan::command('admin:reset-password {email}', function (string $email) {
    $user = \App\Models\User::where('email', strtolower(trim($email)))->first();

    if (! $user) {
        $this->error("No user with email {$email}.");

        return 1;
    }

    $password = \Illuminate\Support\Str::password(16, symbols: false);
    $user->forceFill(['password' => $password, 'is_active' => true])->save();

    $this->info("New password for {$user->email}: {$password}");
    $this->line('Log in and change it on the Profile page.');

    return 0;
})->purpose('Set a new random password for a user (e.g. lost Super Admin password)');

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

Artisan::command('payments:sync', function (App\Services\Payments\PaymentService $payments) {
    $pending = App\Models\Payment::where('gateway', App\Models\Payment::GATEWAY_PKPAY)
        ->where('status', App\Models\Payment::PENDING)
        ->whereNotNull('gateway_payment_id')
        ->whereNull('reviewed_by') // set back to pending by the Super Admin → leave it alone
        ->where('created_at', '>=', now()->subDay())
        ->limit(100)->get();

    foreach ($pending as $payment) {
        try {
            $payments->syncPkPay($payment);
        } catch (Throwable $e) {
            $this->warn("{$payment->reference}: {$e->getMessage()}");
        }
    }

    // Older than a day and still pending → expired (Raast QR lives 5 minutes).
    $expired = App\Models\Payment::where('gateway', App\Models\Payment::GATEWAY_PKPAY)
        ->where('status', App\Models\Payment::PENDING)
        ->whereNull('reviewed_by')
        ->where('created_at', '<', now()->subDay())
        ->update(['status' => App\Models\Payment::EXPIRED]);

    $this->info("Checked {$pending->count()} pending PKPay payment(s); expired {$expired}.");
})->purpose('Re-check pending PKPay payments (in case a callback was missed)');

/*
|--------------------------------------------------------------------------
| Scheduler — run `php artisan schedule:run` every minute (cron / Task Scheduler)
|--------------------------------------------------------------------------
*/

Schedule::command('payments:sync')->everyFiveMinutes()->withoutOverlapping();

// Follow-up message before the 24-hour WhatsApp window closes (per-number setting).
Schedule::command('whatsapp:send-followups')->everyFiveMinutes()->withoutOverlapping();

// Detect expired / revoked tokens early (does not send any message).
Schedule::command('whatsapp:check-accounts')->hourly()->withoutOverlapping();

// Delete old processed webhook payloads (WHATSAPP_WEBHOOK_RETENTION_DAYS).
Schedule::command('model:prune', ['--model' => [WebhookEvent::class]])->daily();

Schedule::command('queue:prune-failed --hours=720')->daily();
