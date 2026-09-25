<?php

use App\Http\Controllers\Meetings\MeetingCalendarController;
use App\Http\Controllers\Meetings\MeetingCancellationController;
use App\Http\Controllers\Meetings\MeetingCompletionController;
use App\Http\Controllers\Meetings\MeetingController;
use App\Http\Controllers\Meetings\MeetingParticipantController;
use App\Http\Controllers\Meetings\MeetingRescheduleController;
use App\Http\Controllers\Meetings\MeetingStatusController;
use Illuminate\Support\Facades\Route;

/*
| Loaded inside the web + auth + active + throttle:crm group.
| Route middleware is the first gate; MeetingPolicy re-checks meeting AND
| linked-lead visibility per record. There is no meeting export / .ics route.
*/

$anyMeetingView = 'permission:meeting.view|meeting.view_all';

Route::middleware($anyMeetingView)->group(function () {
    Route::get('meetings', [MeetingController::class, 'index'])->name('meetings.index');
    Route::post('meetings', [MeetingController::class, 'store'])->middleware('permission:meeting.create')->name('meetings.store');
    Route::get('meetings/participants/search', [MeetingParticipantController::class, 'search'])->middleware(['permission:meeting.create', 'throttle:search'])->name('meetings.participants.search');
    Route::get('meetings/{meeting}', [MeetingController::class, 'show'])->withTrashed()->name('meetings.show');
    Route::put('meetings/{meeting}', [MeetingController::class, 'update'])->middleware('permission:meeting.edit')->name('meetings.update');
    Route::delete('meetings/{meeting}', [MeetingController::class, 'destroy'])->middleware('permission:meeting.delete')->name('meetings.destroy');
    Route::post('meetings/{meeting}/restore', [MeetingController::class, 'restore'])->withTrashed()->middleware('permission:meeting.delete')->name('meetings.restore');

    Route::post('meetings/{meeting}/confirm', [MeetingStatusController::class, 'confirm'])->middleware('permission:meeting.edit')->name('meetings.confirm');
    Route::post('meetings/{meeting}/start', [MeetingStatusController::class, 'start'])->middleware('permission:meeting.complete')->name('meetings.start');
    Route::post('meetings/{meeting}/no-show', [MeetingStatusController::class, 'noShow'])->middleware('permission:meeting.complete')->name('meetings.no-show');
    Route::post('meetings/{meeting}/complete', [MeetingCompletionController::class, 'store'])->middleware('permission:meeting.complete')->name('meetings.complete');
    Route::post('meetings/{meeting}/reschedule', [MeetingRescheduleController::class, 'store'])->middleware('permission:meeting.edit')->name('meetings.reschedule');
    Route::post('meetings/{meeting}/cancel', [MeetingCancellationController::class, 'store'])->middleware('permission:meeting.cancel')->name('meetings.cancel');

    Route::post('meetings/{meeting}/participants', [MeetingParticipantController::class, 'store'])->middleware('permission:meeting.edit')->name('meetings.participants.store');
    Route::delete('meetings/{meeting}/participants/{participant}', [MeetingParticipantController::class, 'destroy'])->middleware('permission:meeting.edit')->name('meetings.participants.destroy');
    Route::post('meetings/{meeting}/respond', [MeetingParticipantController::class, 'respond'])->name('meetings.respond');

    Route::get('calendar', [MeetingCalendarController::class, 'index'])->name('calendar.index');
    Route::get('calendar/events', [MeetingCalendarController::class, 'events'])->name('calendar.events');
});
