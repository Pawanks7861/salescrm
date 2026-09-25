<?php

namespace App\Console\Commands;

use App\Jobs\SendMeetingReminder;
use App\Services\Meetings\MeetingReminderService;
use Illuminate\Console\Command;

/**
 * Scheduled every minute. Claims due meeting reminder rows (indexed status +
 * remind_at lookup) and queues one job per row. Quiet when nothing is due.
 */
class DispatchMeetingReminders extends Command
{
    protected $signature = 'meetings:dispatch-reminders {--limit=200 : Maximum reminders to claim per run}';

    protected $description = 'Queue due meeting reminders (idempotent)';

    public function handle(MeetingReminderService $reminders): int
    {
        $claimed = $reminders->claimDue(max(1, (int) $this->option('limit')));

        foreach ($claimed as $id) {
            SendMeetingReminder::dispatch($id);
        }

        if ($claimed->isNotEmpty()) {
            $this->info("Queued {$claimed->count()} meeting reminder(s).");
        }

        return self::SUCCESS;
    }
}
