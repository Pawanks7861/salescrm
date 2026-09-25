<?php

namespace App\Console\Commands;

use App\Jobs\SendFollowupReminder;
use App\Services\Followups\FollowupReminderService;
use Illuminate\Console\Command;

/**
 * Scheduled every minute. Claims due reminder rows (indexed status +
 * remind_at lookup) and queues one job per row. Quiet when nothing is due.
 */
class DispatchFollowupReminders extends Command
{
    protected $signature = 'followups:dispatch-reminders {--limit=200 : Maximum reminders to claim per run}';

    protected $description = 'Queue due follow-up reminders and overdue alerts (idempotent)';

    public function handle(FollowupReminderService $reminders): int
    {
        $claimed = $reminders->claimDue(max(1, (int) $this->option('limit')));

        foreach ($claimed as $id) {
            SendFollowupReminder::dispatch($id);
        }

        if ($claimed->isNotEmpty()) {
            $this->info("Queued {$claimed->count()} follow-up reminder(s).");
        }

        return self::SUCCESS;
    }
}
