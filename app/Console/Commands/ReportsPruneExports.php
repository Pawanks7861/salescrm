<?php

namespace App\Console\Commands;

use App\Services\Reports\ReportExportService;
use Illuminate\Console\Command;

class ReportsPruneExports extends Command
{
    protected $signature = 'reports:prune-exports';

    protected $description = 'Delete expired report export files (metadata rows are kept for the audit trail)';

    public function handle(ReportExportService $exports): int
    {
        $count = $exports->prune();
        $this->info("Expired {$count} report export(s).");

        return self::SUCCESS;
    }
}
