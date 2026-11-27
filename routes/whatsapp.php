<?php

use App\Http\Controllers\BillingController;
use App\Http\Controllers\BotFlowController;
use App\Http\Controllers\PaymentAdminController;
use App\Http\Controllers\Chat\ChatController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\SettingsController;
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

        // ---- WhatsApp Manager (health, business profile, PIN, blocked, charges) ----
        $manager = \App\Http\Controllers\WhatsAppManagerController::class;
        Route::get('whatsapp-accounts/{account}/manage/{tab?}', [$manager, 'show'])
            ->whereIn('tab', array_keys(\App\Http\Controllers\WhatsAppManagerController::TABS))->name('accounts.manage');
        Route::post('whatsapp-accounts/{account}/manage/health', [$manager, 'refreshHealth'])->middleware('throttle:whatsapp-api-check')->name('accounts.manage.health');
        Route::put('whatsapp-accounts/{account}/manage/profile', [$manager, 'updateProfile'])->middleware('throttle:10,1')->name('accounts.manage.profile');
        Route::post('whatsapp-accounts/{account}/manage/app-id', [$manager, 'saveAppId'])->name('accounts.manage.app-id');
        Route::post('whatsapp-accounts/{account}/manage/pin', [$manager, 'updatePin'])->middleware('throttle:5,1')->name('accounts.manage.pin');
        Route::post('whatsapp-accounts/{account}/manage/rates', [$manager, 'saveRates'])->name('accounts.manage.rates');
        Route::post('whatsapp-accounts/{account}/manage/followup', [$manager, 'saveFollowUp'])->name('accounts.manage.followup');
    });

    // ---- Block / unblock a customer on WhatsApp ------------------------------
    Route::middleware(['permission:'.P::CONTACTS_MANAGE, 'throttle:20,1'])->group(function () {
        Route::post('contacts/{contact}/block', [\App\Http\Controllers\ContactBlockController::class, 'store'])->name('contacts.block');
        Route::delete('contacts/{contact}/block', [\App\Http\Controllers\ContactBlockController::class, 'destroy'])->name('contacts.unblock');
    });

    // ---- Chat inbox (Phase 5) + sending (Phase 4) ---------------------------
    Route::prefix('chat')->name('chat.')->middleware('permission:'.P::CHATS_VIEW)->group(function () {
        Route::get('/', [ChatController::class, 'index'])->name('index');
        Route::get('conversations', [ChatController::class, 'conversations'])->name('conversations');
        Route::get('unread-summary', [ChatController::class, 'unreadSummary'])->middleware('throttle:120,1')->name('unread-summary');
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
            Route::get('leads/export', [\App\Http\Controllers\LeadController::class, 'export'])->middleware('throttle:10,1')->name('leads.export');
        });

        // Leads sheet (first message of every new number).
        Route::get('leads', [\App\Http\Controllers\LeadController::class, 'index'])->name('leads.index');

        Route::resource('contacts', ContactController::class);
        Route::post('contacts/{contact}/notes', [ContactController::class, 'storeNote'])->name('contacts.notes.store');
        Route::delete('contacts/{contact}/notes/{note}', [ContactController::class, 'destroyNote'])->name('contacts.notes.destroy');
    });

    // ---- Message templates (Phase 6) ------------------------------------------
    Route::middleware('permission:'.P::TEMPLATES_VIEW)->group(function () {
        Route::get('templates', [TemplateController::class, 'index'])->name('templates.index');

        // Create / delete templates (submitted to Meta for review) — before {template} routes.
        Route::middleware('permission:'.P::TEMPLATES_SYNC)->group(function () {
            Route::get('templates/create', [\App\Http\Controllers\TemplateBuilderController::class, 'create'])->name('templates.create');
            Route::post('templates', [\App\Http\Controllers\TemplateBuilderController::class, 'store'])
                ->middleware('throttle:whatsapp-api-check')->name('templates.store');
            Route::delete('templates/{template}', [\App\Http\Controllers\TemplateBuilderController::class, 'destroy'])->name('templates.destroy');
        });

        Route::get('templates/{template}', [TemplateController::class, 'show'])->name('templates.show');
        Route::post('templates/sync', [TemplateController::class, 'sync'])
            ->middleware(['permission:'.P::TEMPLATES_SYNC, 'throttle:whatsapp-api-check'])->name('templates.sync');
        Route::post('templates/{template}/send', [TemplateController::class, 'send'])
            ->middleware(['permission:'.P::TEMPLATES_SEND, 'throttle:whatsapp-send'])->name('templates.send');
    });

    // ---- Plans + registration switch + payments (Super Admin) ----------------
    Route::middleware('permission:'.P::PLANS_MANAGE)->group(function () {
        Route::post('plans/settings', [PlanController::class, 'settings'])->name('plans.settings');
        Route::resource('plans', PlanController::class)->except(['show']);

        Route::get('payments', [PaymentAdminController::class, 'index'])->name('payments.index');
        Route::post('payments/{payment}/approve', [PaymentAdminController::class, 'approve'])->name('payments.approve');
        Route::post('payments/{payment}/reject', [PaymentAdminController::class, 'reject'])->name('payments.reject');
        Route::post('payments/{payment}/sync', [PaymentAdminController::class, 'sync'])->middleware('throttle:20,1')->name('payments.sync');
        Route::post('payments/{payment}/status', [PaymentAdminController::class, 'changeStatus'])->name('payments.status'); // Super Admin only (checked in the controller)
    });

    // ---- Billing (customers buy / upgrade their plan) ---------------------------
    Route::prefix('billing')->name('billing.')->group(function () {
        Route::get('/', [BillingController::class, 'index'])->name('index');
        Route::get('checkout/{plan}', [BillingController::class, 'checkout'])->name('checkout');
        Route::post('checkout/{plan}', [BillingController::class, 'start'])->middleware('throttle:10,1')->name('start');
        Route::post('plans/{plan}/free', [BillingController::class, 'free'])->name('free');
        Route::get('payments/{payment}', [BillingController::class, 'payment'])->name('payment');
        Route::get('payments/{payment}/status', [BillingController::class, 'status'])->middleware('throttle:60,1')->name('status');
        Route::post('payments/{payment}/manual', [BillingController::class, 'submitManual'])->name('manual');
    });

    // ---- Bot flows ------------------------------------------------------------
    Route::middleware('permission:'.P::BOTS_MANAGE)->group(function () {
        Route::resource('bot-flows', BotFlowController::class)->except(['show'])->names('bots')->parameters(['bot-flows' => 'flow']);
        Route::patch('bot-flows/{flow}/toggle', [BotFlowController::class, 'toggle'])->name('bots.toggle');

        // Auto Replies (always reply / auto reply / quick action buttons) — separate from Bot Flows.
        Route::resource('auto-replies', \App\Http\Controllers\AutoReplyController::class)->except(['show'])->names('auto-replies')->parameters(['auto-replies' => 'rule']);
        Route::patch('auto-replies/{rule}/toggle', [\App\Http\Controllers\AutoReplyController::class, 'toggle'])->name('auto-replies.toggle');
    });
    Route::patch('chat/conversations/{conversation}/bot', [BotFlowController::class, 'conversationBot'])
        ->middleware('permission:'.P::CHATS_VIEW)->name('chat.bot');

    // ---- Settings center (Super Admin) ----------------------------------------
    Route::middleware('permission:'.P::SETTINGS_MANAGE)->group(function () {
        Route::redirect('settings', 'settings/general')->name('settings.index');

        Route::get('settings/meta', [MetaSettingsController::class, 'edit'])->name('settings.meta');
        Route::put('settings/meta', [MetaSettingsController::class, 'update'])->name('settings.meta.update');
        Route::post('settings/meta/test', [MetaSettingsController::class, 'test'])
            ->middleware('throttle:whatsapp-api-check')->name('settings.meta.test');

        Route::post('settings/email/test', [SettingsController::class, 'testEmail'])
            ->middleware('throttle:5,1')->name('settings.email.test');
        Route::get('settings/{section}', [SettingsController::class, 'edit'])
            ->whereIn('section', SettingsController::SECTIONS)->name('settings.edit');
        Route::put('settings/{section}', [SettingsController::class, 'update'])
            ->whereIn('section', SettingsController::SECTIONS)->name('settings.update');
    });

    // ---- Media (private storage, authorised) --------------------------------
    Route::get('media/{attachment}', [MediaController::class, 'show'])
        ->middleware('permission:'.P::CHATS_VIEW)->name('media.show');
});
