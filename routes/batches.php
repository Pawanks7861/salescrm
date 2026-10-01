<?php

use App\Http\Controllers\Batches\BatchController;
use App\Http\Controllers\Batches\BatchLeadController;
use App\Http\Controllers\Batches\BatchTrainerController;
use Illuminate\Support\Facades\Route;

/*
| Loaded inside the web + auth + active + throttle:crm group.
| Route middleware is the first gate; BatchPolicy re-checks the batch and
| BatchService re-checks every lead id against LeadVisibility.
| There is intentionally NO batch or lead export route.
*/

Route::middleware('permission:batch.view')->group(function () {
    Route::get('batches', [BatchController::class, 'index'])->name('batches.index');
    Route::get('batches/create', [BatchController::class, 'create'])->middleware('permission:batch.create')->name('batches.create');
    Route::post('batches', [BatchController::class, 'store'])->middleware('permission:batch.create')->name('batches.store');
    Route::get('batches/lookup', [BatchController::class, 'lookup'])->middleware(['permission:batch.manage_leads', 'throttle:search'])->name('batches.lookup');
    Route::get('batches/lead-search', [BatchLeadController::class, 'search'])->middleware(['permission:batch.manage_leads', 'throttle:search'])->name('batches.lead-search');

    Route::get('batches/{batch}', [BatchController::class, 'show'])->whereNumber('batch')->name('batches.show');
    Route::get('batches/{batch}/edit', [BatchController::class, 'edit'])->whereNumber('batch')->middleware('permission:batch.edit')->name('batches.edit');
    Route::put('batches/{batch}', [BatchController::class, 'update'])->whereNumber('batch')->middleware('permission:batch.edit')->name('batches.update');
    Route::post('batches/{batch}/archive', [BatchController::class, 'archive'])->whereNumber('batch')->middleware('permission:batch.delete')->name('batches.archive');
    Route::post('batches/{batch}/restore', [BatchController::class, 'restore'])->whereNumber('batch')->middleware('permission:batch.delete')->name('batches.restore');
    Route::delete('batches/{batch}', [BatchController::class, 'destroy'])->whereNumber('batch')->middleware('permission:batch.delete')->name('batches.destroy');

    Route::middleware('permission:batch.manage_leads')->group(function () {
        Route::post('batches/{batch}/leads', [BatchLeadController::class, 'store'])->whereNumber('batch')->name('batches.leads.store');
        Route::delete('batches/{batch}/leads', [BatchLeadController::class, 'bulkDestroy'])->whereNumber('batch')->name('batches.leads.bulk-destroy');
        Route::delete('batches/{batch}/leads/{lead}', [BatchLeadController::class, 'destroy'])->whereNumber('batch')->whereNumber('lead')->name('batches.leads.destroy');
    });

    Route::middleware('permission:batch.manage_trainers')->group(function () {
        Route::get('batches/trainer-search', [BatchTrainerController::class, 'search'])->middleware('throttle:search')->name('batches.trainer-search');
        Route::post('batches/{batch}/trainers', [BatchTrainerController::class, 'store'])->whereNumber('batch')->name('batches.trainers.store');
        Route::delete('batches/{batch}/trainers', [BatchTrainerController::class, 'bulkDestroy'])->whereNumber('batch')->name('batches.trainers.bulk-destroy');
        // withTrashed: a deleted user's past assignment can still be removed (BatchPolicy rejects deleted batches).
        Route::delete('batches/{batch}/trainers/{trainer}', [BatchTrainerController::class, 'destroy'])->whereNumber('batch')->whereNumber('trainer')->withTrashed()->name('batches.trainers.destroy');
    });
});
