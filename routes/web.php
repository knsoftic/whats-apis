<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\CurrentAccountController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserController;
use App\Support\Permissions as P;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

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

    Route::get('/activity-logs', [ActivityLogController::class, 'index'])
        ->middleware('permission:'.P::ACTIVITY_VIEW)
        ->name('activity-logs.index');
});

require __DIR__.'/auth.php';
require __DIR__.'/whatsapp.php';
