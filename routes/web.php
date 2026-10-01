<?php

use App\Http\Controllers\BrandingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FcmServiceWorkerController;
use App\Http\Controllers\FcmTokenController;
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PushSubscriptionController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::get('/firebase-messaging-sw.js', FcmServiceWorkerController::class)
    ->middleware('throttle:120,1')
    ->name('fcm.sw');

Route::get('/branding/{type}', [BrandingController::class, 'show'])
    ->whereIn('type', ['logo', 'favicon'])
    ->middleware('throttle:120,1')
    ->name('branding.asset');

Route::middleware(['auth', 'active', 'office', 'throttle:crm'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/notifications', [NotificationPreferenceController::class, 'update'])->name('profile.notifications');
    Route::post('/profile/notifications/test', [NotificationPreferenceController::class, 'test'])->middleware('throttle:sensitive')->name('profile.notifications.test');
    Route::post('/push-subscriptions', [PushSubscriptionController::class, 'store'])->middleware('throttle:sensitive')->name('push-subscriptions.store');
    Route::delete('/push-subscriptions', [PushSubscriptionController::class, 'destroy'])->middleware('throttle:sensitive')->name('push-subscriptions.destroy');
    Route::post('/fcm-tokens', [FcmTokenController::class, 'store'])->middleware('throttle:sensitive')->name('fcm-tokens.store');
    Route::delete('/fcm-tokens', [FcmTokenController::class, 'destroy'])->middleware('throttle:sensitive')->name('fcm-tokens.destroy');

    require __DIR__.'/leads.php';
    require __DIR__.'/followups.php';
    require __DIR__.'/meetings.php';
    require __DIR__.'/reports.php';
});

require __DIR__.'/auth.php';
