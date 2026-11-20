<?php

use App\Http\Controllers\Chat\ChatController;
use App\Http\Controllers\Chat\MessageController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\EmbeddedSignupController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\MetaSettingsController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\WhatsAppAccountController;
use App\Support\Permissions as P;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| WhatsApp dashboard routes (web, authenticated)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth'])->group(function () {

    // ---- WhatsApp Accounts (Phase 2) --------------------------------------
    Route::middleware('permission:'.P::ACCOUNTS_MANAGE)->group(function () {
        // Embedded Signup ("Connect with Meta") — must be declared before the resource's {account} routes.
        Route::get('whatsapp-accounts/connect', [EmbeddedSignupController::class, 'show'])->name('accounts.connect');
        Route::post('whatsapp-accounts/embedded-signup', [EmbeddedSignupController::class, 'store'])
            ->middleware('throttle:whatsapp-api-check')->name('accounts.embedded-signup');
        Route::post('whatsapp-accounts/{account}/register', [EmbeddedSignupController::class, 'register'])
            ->middleware('throttle:whatsapp-api-check')->name('accounts.register');

        Route::resource('whatsapp-accounts', WhatsAppAccountController::class)
            ->names('accounts')
            ->parameters(['whatsapp-accounts' => 'account']);

        Route::post('whatsapp-accounts/{account}/test', [WhatsAppAccountController::class, 'test'])
            ->middleware('throttle:whatsapp-api-check')->name('accounts.test');
        Route::post('whatsapp-accounts/{account}/subscribe', [WhatsAppAccountController::class, 'subscribe'])
            ->middleware('throttle:whatsapp-api-check')->name('accounts.subscribe');
        Route::patch('whatsapp-accounts/{account}/toggle', [WhatsAppAccountController::class, 'toggle'])
            ->name('accounts.toggle');
        Route::get('whatsapp-accounts/{account}/verify-token', [WhatsAppAccountController::class, 'verifyToken'])
            ->middleware('throttle:20,1')->name('accounts.verify-token');
    });

    // ---- Chat inbox (Phase 5) + sending (Phase 4) ---------------------------
    Route::prefix('chat')->name('chat.')->middleware('permission:'.P::CHATS_VIEW)->group(function () {
        Route::get('/', [ChatController::class, 'index'])->name('index');
        Route::get('conversations', [ChatController::class, 'conversations'])->name('conversations');
        Route::get('conversations/{conversation}', [ChatController::class, 'show'])->name('show');
        Route::post('conversations/{conversation}/read', [ChatController::class, 'markRead'])->name('read');
        Route::post('conversations/{conversation}/unread', [ChatController::class, 'markUnread'])->name('unread');
        Route::patch('conversations/{conversation}', [ChatController::class, 'update'])->name('update');
        Route::get('search', [ChatController::class, 'search'])->middleware('throttle:60,1')->name('search');
        Route::get('templates', [ChatController::class, 'templates'])->name('templates');

        Route::middleware(['permission:'.P::CHATS_SEND, 'throttle:whatsapp-send'])->group(function () {
            Route::post('conversations/{conversation}/messages', [MessageController::class, 'storeText'])->name('messages.text');
            Route::post('conversations/{conversation}/media', [MessageController::class, 'storeMedia'])->name('messages.media');
            Route::post('messages/{message}/retry', [MessageController::class, 'retry'])->name('messages.retry');
        });

        Route::middleware(['permission:'.P::TEMPLATES_SEND, 'throttle:whatsapp-send'])->group(function () {
            Route::post('conversations/{conversation}/template', [MessageController::class, 'storeTemplate'])->name('messages.template');
            Route::post('start', [MessageController::class, 'start'])->name('start');
        });
    });

    // ---- Contacts (Phase 6) -------------------------------------------------
    Route::middleware('permission:'.P::CONTACTS_VIEW)->group(function () {
        Route::middleware('permission:'.P::CONTACTS_IMPORT_EXPORT)->group(function () {
            Route::get('contacts/import', [ContactController::class, 'importForm'])->name('contacts.import.form');
            Route::post('contacts/import', [ContactController::class, 'import'])->middleware('throttle:10,1')->name('contacts.import');
            Route::get('contacts/export', [ContactController::class, 'export'])->middleware('throttle:10,1')->name('contacts.export');
        });

        Route::resource('contacts', ContactController::class);
        Route::post('contacts/{contact}/notes', [ContactController::class, 'storeNote'])->name('contacts.notes.store');
        Route::delete('contacts/{contact}/notes/{note}', [ContactController::class, 'destroyNote'])->name('contacts.notes.destroy');
    });

    // ---- Message templates (Phase 6) ------------------------------------------
    Route::middleware('permission:'.P::TEMPLATES_VIEW)->group(function () {
        Route::get('templates', [TemplateController::class, 'index'])->name('templates.index');
        Route::get('templates/{template}', [TemplateController::class, 'show'])->name('templates.show');
        Route::post('templates/sync', [TemplateController::class, 'sync'])
            ->middleware(['permission:'.P::TEMPLATES_SYNC, 'throttle:whatsapp-api-check'])->name('templates.sync');
        Route::post('templates/{template}/send', [TemplateController::class, 'send'])
            ->middleware(['permission:'.P::TEMPLATES_SEND, 'throttle:whatsapp-send'])->name('templates.send');
    });

    // ---- Settings → Meta Tech Provider (Super Admin) -------------------------
    Route::middleware('permission:'.P::SETTINGS_MANAGE)->group(function () {
        Route::get('settings/meta', [MetaSettingsController::class, 'edit'])->name('settings.meta');
        Route::put('settings/meta', [MetaSettingsController::class, 'update'])->name('settings.meta.update');
        Route::post('settings/meta/test', [MetaSettingsController::class, 'test'])
            ->middleware('throttle:whatsapp-api-check')->name('settings.meta.test');
    });

    // ---- Media (private storage, authorised) --------------------------------
    Route::get('media/{attachment}', [MediaController::class, 'show'])
        ->middleware('permission:'.P::CHATS_VIEW)->name('media.show');
});
