<?php

use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Meta WhatsApp webhooks (public — no session, no CSRF)
|--------------------------------------------------------------------------
| 1. Per-account URL (numbers added manually with their own Meta app):
|      https://YOUR-DOMAIN/api/webhooks/whatsapp/{uuid}
|    {account} is the account's random UUID (never the numeric id).
|
| 2. Shared platform URL (your Tech Provider app — Embedded Signup numbers):
|      https://YOUR-DOMAIN/api/webhooks/whatsapp
|    Signed with the platform App Secret; routed by phone_number_id / WABA id.
*/

Route::middleware('throttle:whatsapp-webhook')->group(function () {
    Route::get('webhooks/whatsapp', [WhatsAppWebhookController::class, 'verifyPlatform'])
        ->name('webhooks.whatsapp.platform');

    Route::post('webhooks/whatsapp', [WhatsAppWebhookController::class, 'receivePlatform'])
        ->name('webhooks.whatsapp.platform.receive');

    Route::get('webhooks/whatsapp/{account}', [WhatsAppWebhookController::class, 'verify'])
        ->whereUuid('account')
        ->name('webhooks.whatsapp.verify');

    Route::post('webhooks/whatsapp/{account}', [WhatsAppWebhookController::class, 'receive'])
        ->whereUuid('account')
        ->name('webhooks.whatsapp.receive');
});
