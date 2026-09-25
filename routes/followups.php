<?php

use App\Http\Controllers\Followups\FollowupCancellationController;
use App\Http\Controllers\Followups\FollowupCompletionController;
use App\Http\Controllers\Followups\FollowupController;
use App\Http\Controllers\Followups\FollowupRescheduleController;
use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

/*
| Loaded inside the web + auth + active + throttle:crm group.
| Route middleware is the first gate; FollowupPolicy re-checks follow-up AND
| parent-lead visibility per record. There is no follow-up export route.
*/

$anyFollowupView = 'permission:followup.view|followup.view_all';

Route::middleware($anyFollowupView)->group(function () {
    Route::get('follow-ups', [FollowupController::class, 'index'])->name('followups.index');
    Route::post('follow-ups', [FollowupController::class, 'store'])->middleware('permission:followup.create')->name('followups.store');
    Route::get('follow-ups/{followup}', [FollowupController::class, 'show'])->withTrashed()->name('followups.show');
    Route::put('follow-ups/{followup}', [FollowupController::class, 'update'])->middleware('permission:followup.edit')->name('followups.update');
    Route::delete('follow-ups/{followup}', [FollowupController::class, 'destroy'])->middleware('permission:followup.delete')->name('followups.destroy');
    Route::post('follow-ups/{followup}/restore', [FollowupController::class, 'restore'])->withTrashed()->middleware('permission:followup.delete')->name('followups.restore');

    Route::post('follow-ups/{followup}/complete', [FollowupCompletionController::class, 'store'])->middleware('permission:followup.complete')->name('followups.complete');
    Route::post('follow-ups/{followup}/reschedule', [FollowupRescheduleController::class, 'store'])->middleware('permission:followup.edit')->name('followups.reschedule');
    Route::post('follow-ups/{followup}/cancel', [FollowupCancellationController::class, 'store'])->middleware('permission:followup.cancel')->name('followups.cancel');
});

Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
Route::get('notifications/recent', [NotificationController::class, 'recent'])->name('notifications.recent');
Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->whereUuid('notification')->name('notifications.read');
Route::get('notifications/{notification}/open', [NotificationController::class, 'open'])->whereUuid('notification')->name('notifications.open');
