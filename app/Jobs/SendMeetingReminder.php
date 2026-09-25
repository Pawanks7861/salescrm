<?php

namespace App\Jobs;

use App\Enums\AttendanceStatus;
use App\Enums\AuditAction;
use App\Enums\MeetingParticipantType;
use App\Models\MeetingReminder;
use App\Notifications\Meetings\MeetingReminderNotification;
use App\Services\AuditService;
use App\Services\Meetings\MeetingReminderService;
use App\Services\Meetings\MeetingVisibility;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one claimed meeting reminder. Only acts on rows in `processing`
 * (claimed by meetings:dispatch-reminders), re-validates the meeting, the
 * recipient's participation and access at send time, and only then marks the
 * row sent. Retries are driven by the reminder row, not queue retries, so a
 * reminder is never delivered twice.
 */
class SendMeetingReminder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public int $reminderId) {}

    public function handle(MeetingReminderService $reminders, MeetingVisibility $visibility, AuditService $audit): void
    {
        $reminder = MeetingReminder::query()
            ->with(['meeting.lead', 'meeting.type', 'meeting.participants', 'user'])
            ->find($this->reminderId);

        if (! $reminder || $reminder->status !== MeetingReminder::PROCESSING) {
            return;
        }

        $meeting = $reminder->meeting;
        $user = $reminder->user;

        $stillAttending = $meeting && ((int) $meeting->host_user_id === (int) $reminder->user_id
            || $meeting->participants->contains(fn ($p) => $p->participant_type === MeetingParticipantType::User
                && (int) $p->user_id === (int) $reminder->user_id
                && $p->attendance_status !== AttendanceStatus::Declined));

        if (! $meeting || $meeting->trashed() || ! $meeting->isUpcoming() || $meeting->start_at->lt(now()->subMinutes(5))
            || ! $stillAttending
            || ! $user || $user->trashed() || ! $user->is_active
            || ! $visibility->canView($user, $meeting)) {
            $reminders->discard($reminder);

            return;
        }

        try {
            $user->notify(new MeetingReminderNotification($meeting));
        } catch (Throwable $e) {
            $reminders->release($reminder, $e::class.': '.$e->getMessage());
            Log::warning('Meeting reminder delivery failed', [
                'reminder_id' => $reminder->id,
                'attempts' => $reminder->attempts,
                'error' => $e::class,
            ]);

            return;
        }

        $reminders->markSent($reminder);
        $audit->log(AuditAction::MeetingReminderSent, 'meetings', $meeting,
            "Reminder sent to {$user->name} for {$meeting->meeting_number}",
            null, ['user_id' => $user->id, 'minutes_before' => $reminder->minutes_before]);
    }
}
