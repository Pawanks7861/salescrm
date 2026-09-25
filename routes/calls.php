<?php

use App\Http\Controllers\Calls\CallController;
use App\Http\Controllers\Calls\CallOutcomeController;
use App\Http\Controllers\Calls\CallRecordingController;
use App\Http\Controllers\Calls\FakeTelephonyController;
use App\Http\Controllers\Calls\TelephonySessionController;
use Illuminate\Support\Facades\Route;

/*
| Loaded inside the web + auth + active + throttle:crm group.
| Route middleware is the first gate; CallPolicy re-checks call AND linked-lead
| visibility per record. There is no call export, CSV or bulk recording download.
*/

$anyCallView = 'permission:call.view|call.view_all';

Route::middleware($anyCallView)->group(function () {
    Route::get('calls', [CallController::class, 'index'])->name('calls.index');
    Route::get('calls/active', [CallController::class, 'active'])->name('calls.active');
    Route::get('calls/{call}', [CallController::class, 'show'])->name('calls.show');
    Route::get('calls/{call}/status', [CallController::class, 'status'])->name('calls.status');

    Route::get('calls/{call}/outcome', [CallController::class, 'outcome'])->middleware('permission:call.add_disposition')->name('calls.outcome.options');
    Route::post('calls/{call}/outcome', [CallOutcomeController::class, 'store'])->middleware('permission:call.add_disposition')->name('calls.outcome');
    Route::patch('calls/{call}/notes', [CallOutcomeController::class, 'notes'])->middleware('permission:call.edit_notes')->name('calls.notes');

    Route::get('calls/{call}/recording', [CallRecordingController::class, 'stream'])->middleware('permission:call.recording.listen')->name('calls.recording');
    Route::get('calls/{call}/recording/download', [CallRecordingController::class, 'download'])->middleware(['permission:call.recording.download', 'throttle:sensitive'])->name('calls.recording.download');
});

Route::post('calls', [CallController::class, 'store'])->middleware(['permission:call.make', 'throttle:telephony'])->name('calls.store');

Route::get('telephony/config', [TelephonySessionController::class, 'config'])->name('telephony.config');
Route::post('telephony/session', [TelephonySessionController::class, 'session'])->middleware(['permission:call.make', 'throttle:telephony'])->name('telephony.session');
Route::post('telephony/incoming/identify', [TelephonySessionController::class, 'identify'])->middleware(['permission:call.receive', 'throttle:search'])->name('telephony.identify');

// Local simulator (fake driver + local/testing only; 404 otherwise).
Route::post('telephony/fake/calls/{call}', [FakeTelephonyController::class, 'simulate'])->name('telephony.fake.simulate');
Route::post('telephony/fake/incoming', [FakeTelephonyController::class, 'incoming'])->middleware('permission:call.receive')->name('telephony.fake.incoming');
