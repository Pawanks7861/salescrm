<?php

use App\Http\Controllers\Reports\ReportController;
use App\Http\Controllers\Reports\ReportExportController;
use Illuminate\Support\Facades\Route;

/*
| Loaded inside the web + auth + active + throttle:crm group.
| Route middleware is the first gate; ReportScope re-derives the report tier
| and module visibility for every query. Export requires report.export and is
| re-checked in ReportExportService (403 + audit), not only hidden in the UI.
*/

Route::middleware('permission:report.view|report.view_all')->group(function () {
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/{report}', [ReportController::class, 'show'])->where('report', '[a-z\-]+')->name('reports.show');

    Route::post('reports/{report}/export', [ReportExportController::class, 'store'])
        ->where('report', '[a-z\-]+')->middleware('throttle:10,1')->name('reports.export');
    Route::get('report-exports/{export}', [ReportExportController::class, 'download'])->name('reports.exports.download');
});
