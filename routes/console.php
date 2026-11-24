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

Artisan::command('whatsapp:realtime-check', function () {
    // Live chat updates need: same Reverb key in .env, in the built JS and in the running
    // Reverb process, Reverb listening on REVERB_SERVER_PORT, and Nginx proxying /app + /apps.
    $fail = 0;
    $ok = fn (string $m) => $this->line("  <info>✔</info> {$m}");
    $bad = function (string $m, string $fix = '') use (&$fail) {
        $fail++;
        $this->line("  <error>✘</error> {$m}");
        if ($fix !== '') {
            $this->line("    → {$fix}");
        }
    };

    $driver = (string) config('broadcasting.default');
    if ($driver !== 'reverb') {
        $bad("BROADCAST_CONNECTION is \"{$driver}\" (live updates are off).", 'Set BROADCAST_CONNECTION=reverb in .env, then: php artisan optimize');

        return 1;
    }

    $cfg = config('broadcasting.connections.reverb');
    $key = (string) ($cfg['key'] ?? '');
    $appId = (string) ($cfg['app_id'] ?? '');
    $secret = (string) ($cfg['secret'] ?? '');
    if ($key === '' || $appId === '' || $secret === '') {
        $bad('REVERB_APP_ID / REVERB_APP_KEY / REVERB_APP_SECRET are missing in .env.', 'Run: php artisan reverb:install');

        return 1;
    }
    $ok("Broadcast driver reverb, app key {$key}");

    // 1. The Reverb server config lists the same app.
    $apps = collect(config('reverb.apps.apps', []));
    $apps->contains(fn ($a) => (string) ($a['key'] ?? '') === $key && (string) ($a['app_id'] ?? '') === $appId)
        ? $ok('config/reverb.php serves this app')
        : $bad('config/reverb.php does not list this app key.', 'php artisan optimize:clear && php artisan optimize');

    // 2. The browser bundle was built with the same key.
    $built = collect(glob(public_path('build/assets/*.js')) ?: [])
        ->map(fn ($f) => preg_match('/broadcaster:"reverb",key:"([^"]*)"/', (string) file_get_contents($f), $m) ? $m[1] : null)
        ->filter()->unique()->values();
    if ($built->isEmpty()) {
        $bad('No Reverb key found in public/build (frontend not built?).', 'npm run build');
    } elseif (! $built->contains($key)) {
        $bad('The built JavaScript uses another key ('.$built->implode(', ').').', 'npm run build   (it reads REVERB_APP_KEY from .env)');
    } else {
        $ok('Built JavaScript uses the same key');
    }

    // 3. Reverb is listening locally, and it is OUR Reverb (knows this app id + secret).
    $port = (int) config('reverb.servers.reverb.port', 8080);
    $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 2);
    if (! $sock) {
        $bad("Nothing is listening on 127.0.0.1:{$port}.", 'supervisorctl restart kn-whatsapp-reverb   (or: php artisan reverb:start)');
    } else {
        fclose($sock);
        $ok("Port {$port} is open");
        try {
            (new Pusher\Pusher($key, $secret, $appId, ['host' => '127.0.0.1', 'port' => $port, 'scheme' => 'http', 'useTLS' => false, 'timeout' => 5]))->get('/channels', [], true);
            $ok("Reverb on port {$port} accepts this app");
        } catch (Throwable $e) {
            $owner = '';
            if (PHP_OS_FAMILY === 'Linux') {
                $ss = Illuminate\Support\Facades\Process::run(['sh', '-c', "ss -ltnp 'sport = :{$port}' 2>/dev/null"])->output();
                if (preg_match('/pid=(\d+)/', $ss, $m) && is_readable("/proc/{$m[1]}/cmdline")) {
                    $owner = trim(str_replace("\0", ' ', (string) file_get_contents("/proc/{$m[1]}/cmdline")));
                }
            }
            $bad(
                "The server on port {$port} rejects this app (".Illuminate\Support\Str::limit($e->getMessage(), 120).').'.($owner ? " Process: {$owner}" : ''),
                match (true) {
                    str_contains($owner, base_path('artisan')) => 'Our Reverb runs with an old config → php artisan optimize && supervisorctl restart kn-whatsapp-reverb',
                    $owner !== '' => "Another program uses port {$port} → run the installer again (it moves Reverb to a free port).",
                    default => "Restart Reverb so it reloads .env: php artisan optimize && supervisorctl restart kn-whatsapp-reverb. Still failing → another program uses port {$port}: run the installer again (it moves Reverb to a free port).",
                }
            );
        }
    }

    // 4. End to end through Nginx (the same path the browser uses): publish a test event.
    try {
        Illuminate\Support\Facades\Broadcast::connection('reverb')->broadcast(['kn-realtime-check'], 'ping', ['at' => now()->toIso8601String()]);
        $ok('Test event published via '.($cfg['options']['scheme'] ?? 'https').'://'.($cfg['options']['host'] ?? '?').':'.($cfg['options']['port'] ?? '').' (Nginx → Reverb)');
    } catch (Throwable $e) {
        $bad('Publishing through '.($cfg['options']['host'] ?? '?').' failed: '.Illuminate\Support\Str::limit($e->getMessage(), 160), preg_match('/No matching application|Application does not exist|Invalid auth signature/i', $e->getMessage())
            ? 'Nginx reaches a Reverb that does not know this app (old config or another program on the port) — see the fix above.'
            : 'Check the Nginx "location ~ ^/(app|apps)/" proxy block and REVERB_HOST / REVERB_PORT / REVERB_SCHEME.');
    }

    $this->newLine();
    $fail === 0
        ? $this->info('Realtime OK — chats update instantly.')
        : $this->warn("{$fail} problem(s). Until fixed, the chat page still auto-refreshes every 5 seconds.");

    return $fail === 0 ? 0 : 1;
})->purpose('Check live chat updates (Reverb key, build, port, Nginx proxy)');

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
