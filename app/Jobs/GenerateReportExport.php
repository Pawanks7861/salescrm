<?php

namespace App\Jobs;

use App\Models\ReportExport;
use App\Services\Reports\ReportExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Builds a large report export in the background. Scope and permissions are
 * re-resolved for the requesting user at generation time, so a user who lost
 * access in the meantime gets a failed export, not data.
 */
class GenerateReportExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $exportId) {}

    public function handle(ReportExportService $exports): void
    {
        $export = ReportExport::query()->find($this->exportId);

        if (! $export || $export->status !== ReportExport::QUEUED) {
            return;
        }

        $exports->generate($export);
    }
}
