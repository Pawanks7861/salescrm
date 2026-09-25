<?php

namespace App\Jobs;

use App\Enums\AuditAction;
use App\Models\FollowupReminder;
use App\Notifications\Followups\FollowupReminderNotification;
use App\Services\AuditService;
use App\Services\Followups\FollowupReminderService;
use App\Services\Followups\FollowupVisibility;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one claimed reminder. Only acts on rows in `processing` (claimed by
 * followups:dispatch-reminders), re-validates the follow-up and the
 * assignee's access at send time, and only then marks the row sent. Retries
 * are driven by the reminder row, not by queue retries, so a reminder is
 * never delivered twice.
 */
class SendFollowupReminder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public int $reminderId) {}

    public function handle(FollowupReminderService $reminders, FollowupVisibility $visibility, AuditService $audit): void
    {
        // A row reclaimed after a worker stall can be queued twice; only one
        // job may deliver it, and it re-reads the row under the lock.
        Cache::lock("followup-reminder:{$this->reminderId}", 120)
            ->get(fn () => $this->deliver($reminders, $visibility, $audit));
    }

    private function deliver(FollowupReminderService $reminders, FollowupVisibility $visibility, AuditService $audit): void
    {
        $reminder = FollowupReminder::query()
            ->with(['followup.lead', 'followup.assignee', 'followup.type'])
            ->find($this->reminderId);

        if (! $reminder || $reminder->status !== FollowupReminder::PROCESSING) {
            return;
        }

        $followup = $reminder->followup;
        $assignee = $followup?->assignee;

        if (! $followup || $followup->trashed() || ! $followup->isPending()
            || ! $assignee || $assignee->trashed() || ! $assignee->is_active
            || ! $visibility->canView($assignee, $followup)) {
            $reminders->discard($reminder);

            return;
        }

        try {
            $assignee->notify(new FollowupReminderNotification($followup, $reminder->kind));
        } catch (Throwable $e) {
            $reminders->release($reminder, $e::class.': '.$e->getMessage());
            Log::warning('Follow-up reminder delivery failed', [
                'reminder_id' => $reminder->id,
                'attempts' => $reminder->attempts,
                'error' => $e::class,
            ]);

            return;
        }

        $reminders->markSent($reminder);
        $audit->log(AuditAction::FollowupReminderSent, 'followups', $followup,
            ($reminder->kind === FollowupReminder::KIND_OVERDUE ? 'Overdue alert' : 'Reminder')." sent to {$assignee->name} for follow-up on {$followup->lead->lead_number}",
            null, ['kind' => $reminder->kind, 'assigned_to' => $assignee->id]);
    }
}
