<?php

use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Meta WhatsApp webhooks (public — no session, no CSRF)
|--------------------------------------------------------------------------
| {account} is the account's random UUID (never the numeric id), so the
| callback URL cannot be guessed.  Callback URL to paste into Meta:
|   https://YOUR-DOMAIN/api/webhooks/whatsapp/{uuid}
*/

Route::middleware('throttle:whatsapp-webhook')->group(function () {
    Route::get('webhooks/whatsapp/{account}', [WhatsAppWebhookController::class, 'verify'])
        ->whereUuid('account')
        ->name('webhooks.whatsapp.verify');

    Route::post('webhooks/whatsapp/{account}', [WhatsAppWebhookController::class, 'receive'])
        ->whereUuid('account')
        ->name('webhooks.whatsapp.receive');
});
