<?php

use App\Http\Controllers\Leads\LeadAssignmentController;
use App\Http\Controllers\Leads\LeadAttachmentController;
use App\Http\Controllers\Leads\LeadController;
use App\Http\Controllers\Leads\LeadDuplicateController;
use App\Http\Controllers\Leads\LeadFollowupRequiredController;
use App\Http\Controllers\Leads\LeadNoteController;
use App\Http\Controllers\Leads\LeadPipelineController;
use App\Http\Controllers\Leads\LeadSearchController;
use App\Http\Controllers\Leads\LeadStatusController;
use Illuminate\Support\Facades\Route;

/*
| Loaded inside the web + auth + active + throttle:crm group.
| Route middleware is the first gate; LeadPolicy re-checks visibility per record.
| There is intentionally NO lead export route in Phase 2.
*/

$anyLeadView = 'permission:lead.view|lead.view_all';

Route::middleware($anyLeadView)->group(function () {
    Route::get('leads', [LeadController::class, 'index'])->name('leads.index');
    Route::get('leads/follow-up-required', LeadFollowupRequiredController::class)->name('leads.follow-up-required');
    Route::put('leads/columns', [LeadController::class, 'updateColumns'])->name('leads.columns');
    Route::get('leads/pipeline', [LeadPipelineController::class, 'index'])->name('leads.pipeline');
    Route::get('leads/pipeline/{status}/more', [LeadPipelineController::class, 'more'])->middleware('throttle:search')->name('leads.pipeline.more');
    Route::get('search/leads', LeadSearchController::class)->middleware('throttle:search')->name('search.leads');

    Route::get('leads/create', [LeadController::class, 'create'])->middleware('permission:lead.create')->name('leads.create');
    Route::post('leads', [LeadController::class, 'store'])->middleware('permission:lead.create')->name('leads.store');
    Route::post('leads/duplicate-check', LeadDuplicateController::class)->middleware(['permission:lead.create', 'throttle:search'])->name('leads.duplicate-check');

    Route::get('leads/{lead}', [LeadController::class, 'show'])->withTrashed()->whereNumber('lead')->name('leads.show');
    Route::get('leads/{lead}/activities', [LeadController::class, 'activities'])->whereNumber('lead')->name('leads.activities');
    Route::get('leads/{lead}/edit', [LeadController::class, 'edit'])->middleware('permission:lead.edit')->name('leads.edit');
    Route::put('leads/{lead}', [LeadController::class, 'update'])->middleware('permission:lead.edit')->name('leads.update');
    Route::post('leads/{lead}/priority', [LeadController::class, 'updatePriority'])->middleware('permission:lead.edit')->name('leads.priority');
    Route::delete('leads/{lead}', [LeadController::class, 'destroy'])->middleware('permission:lead.delete')->name('leads.destroy');
    Route::post('leads/{lead}/restore', [LeadController::class, 'restore'])->withTrashed()->middleware('permission:lead.restore')->name('leads.restore');

    Route::post('leads/{lead}/status', [LeadStatusController::class, 'update'])->middleware('permission:lead.change_status')->name('leads.status');
    Route::post('leads/{lead}/assign', [LeadAssignmentController::class, 'update'])->middleware('permission:lead.assign|lead.reassign')->name('leads.assign');

    Route::post('leads/{lead}/notes', [LeadNoteController::class, 'store'])->name('leads.notes.store');
    Route::put('leads/{lead}/notes/{note}', [LeadNoteController::class, 'update'])->name('leads.notes.update');
    Route::delete('leads/{lead}/notes/{note}', [LeadNoteController::class, 'destroy'])->name('leads.notes.destroy');
    Route::get('leads/{lead}/notes/{note}/history', [LeadNoteController::class, 'history'])->name('leads.notes.history');

    Route::post('leads/{lead}/attachments', [LeadAttachmentController::class, 'store'])->middleware('permission:file.upload')->name('leads.attachments.store');
    Route::get('leads/{lead}/attachments/{attachment}/download', [LeadAttachmentController::class, 'download'])->middleware(['permission:file.download', 'throttle:sensitive'])->name('leads.attachments.download');
    Route::delete('leads/{lead}/attachments/{attachment}', [LeadAttachmentController::class, 'destroy'])->name('leads.attachments.destroy');
});
