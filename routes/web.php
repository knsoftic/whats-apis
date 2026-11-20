<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\ContactMessageController;
use App\Http\Controllers\CurrentAccountController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\LandingEditorController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserController;
use App\Support\Permissions as P;
use Illuminate\Support\Facades\Route;

// Public landing page (content edited on Settings → Landing page).
Route::get('/', [LandingController::class, 'show'])->name('home');
Route::post('/contact', [ContactMessageController::class, 'store'])
    ->middleware('throttle:5,1')->name('landing.contact');

// Public legal pages (also used as Privacy Policy / Terms URLs in the Meta app).
Route::view('/privacy-policy', 'legal.privacy')->name('legal.privacy');
Route::view('/terms-conditions', 'legal.terms')->name('legal.terms');

Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', DashboardController::class)
        ->middleware('permission:'.P::DASHBOARD_VIEW)
        ->name('dashboard');

    // Switch the "current" WhatsApp number used across the dashboard.
    Route::post('/current-account', CurrentAccountController::class)->name('current-account.switch');

    // Own profile (no self-delete in an admin tool).
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');

    // User management — Super Admin only (users.manage).
    Route::middleware('permission:'.P::USERS_MANAGE)->group(function () {
        Route::resource('users', UserController::class)->except(['show']);
    });

    // Landing page editor + contact form inbox — Super Admin (settings.manage).
    Route::middleware('permission:'.P::SETTINGS_MANAGE)->group(function () {
        Route::get('/settings/landing', [LandingEditorController::class, 'edit'])->name('settings.landing');
        Route::put('/settings/landing', [LandingEditorController::class, 'update'])->name('settings.landing.update');
        Route::post('/settings/landing/reset', [LandingEditorController::class, 'reset'])->name('settings.landing.reset');
        Route::post('/settings/landing/media', [LandingEditorController::class, 'upload'])
            ->middleware('throttle:30,1')->name('settings.landing.media');
        Route::delete('/settings/landing/media', [LandingEditorController::class, 'deleteMedia'])->name('settings.landing.media.destroy');

        Route::get('/contact-messages', [ContactMessageController::class, 'index'])->name('contact-messages.index');
        Route::post('/contact-messages/read-all', [ContactMessageController::class, 'markAllRead'])->name('contact-messages.read-all');
        Route::patch('/contact-messages/{message}', [ContactMessageController::class, 'update'])->name('contact-messages.update');
        Route::delete('/contact-messages/{message}', [ContactMessageController::class, 'destroy'])->name('contact-messages.destroy');
    });

    Route::get('/activity-logs', [ActivityLogController::class, 'index'])
        ->middleware('permission:'.P::ACTIVITY_VIEW)
        ->name('activity-logs.index');
});

require __DIR__.'/auth.php';
require __DIR__.'/whatsapp.php';
