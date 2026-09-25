<?php

namespace App\Console\Commands;

use App\Services\Telephony\TelephonyReconciliationService;
use Illuminate\Console\Command;

/**
 * Recovers calls whose final callback never arrived and recordings still
 * pending. Bounded per run; stops early on a provider outage.
 */
class TelephonyReconcilePending extends Command
{
    protected $signature = 'telephony:reconcile-pending {--limit= : Maximum calls to check this run}';

    protected $description = 'Reconcile stale in-progress calls and pending recordings with the telephony provider';

    public function handle(TelephonyReconciliationService $reconciliation): int
    {
        $limit = $this->option('limit') !== null ? max(1, min(1000, (int) $this->option('limit'))) : null;
        $stats = $reconciliation->run($limit);

        $this->info("Checked {$stats['checked']} call(s): {$stats['updated']} updated, {$stats['closed']} closed, {$stats['recordings']} recording(s) re-queued.");

        return self::SUCCESS;
    }
}
