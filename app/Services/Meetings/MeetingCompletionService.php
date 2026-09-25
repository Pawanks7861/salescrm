<?php

namespace App\Services\Meetings;

use App\Enums\AttendanceStatus;
use App\Enums\AuditAction;
use App\Enums\MeetingOutcome;
use App\Enums\MeetingStatus;
use App\Models\Followup;
use App\Models\Meeting;
use App\Models\User;
use App\Services\ActivityService;
use App\Services\AuditService;
use App\Services\Followups\FollowupService;
use App\Services\Followups\LeadFollowupSyncService;
use App\Services\Leads\LeadService;
use App\Services\SettingService;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Completes a meeting in one transaction:
 *   meeting outcome + notes -> participant attendance -> optional lead status
 *   change (Phase 2 LeadService) -> optional next follow-up (Phase 3
 *   FollowupService) -> activity -> audit -> notifications (after commit).
 *
 * Any failure (e.g. Lost without a lost reason, follow-up in the past) rolls
 * the whole completion back. The lead status is never changed from the
 * outcome alone.
 */
class MeetingCompletionService
{
    public function __construct(
        private readonly MeetingReminderService $reminders,
        private readonly MeetingNotificationService $notifications,
        private readonly LeadService $leads,
        private readonly FollowupService $followups,
        private readonly LeadFollowupSyncService $sync,
        private readonly ActivityService $activities,
        private readonly AuditService $audit,
        private readonly SettingService $settings,
    ) {}

    /**
     * `$data`: outcome, notes, attendance[participant_id] (attended|absent),
     * status_id, lost_reason_id, lost_reason_notes, schedule_followup,
     * followup{followup_type_id, scheduled_date, scheduled_time, priority,
     * reminder_minutes, assigned_to, title, description, confirm_duplicate}.
     *
     * @return array{meeting: Meeting, followup: ?Followup}
     *
     * @throws ValidationException
     */
    public function complete(Meeting $meeting, array $data, User $actor): array
    {
        if ($meeting->trashed() || ! $meeting->isOpen()) {
            throw ValidationException::withMessages(['status' => 'This meeting is closed and can no longer be completed.']);
        }

        $lead = $meeting->lead;

        $outcome = MeetingOutcome::tryFrom((string) ($data['outcome'] ?? ''));
        if (! $outcome && $this->settings->get('meeting.require_outcome', true)) {
            throw ValidationException::withMessages(['outcome' => 'Select the outcome of this meeting.']);
        }

        $notes = trim((string) ($data['notes'] ?? '')) ?: null;
        if (! $notes && $this->settings->get('meeting.require_notes_on_complete', true)) {
            throw ValidationException::withMessages(['notes' => 'Add meeting notes before completing.']);
        }

        $statusId = ! empty($data['status_id']) ? (int) $data['status_id'] : null;
        if ($statusId && (! $lead || ! $actor->hasPermission(Permissions::LEAD_CHANGE_STATUS) || ! $actor->can('changeStatus', $lead))) {
            throw ValidationException::withMessages(['status_id' => 'You are not allowed to change the lead status.']);
        }

        $scheduleFollowup = ! empty($data['schedule_followup']);
        if ($scheduleFollowup && (! $lead || ! $actor->can('create', [Followup::class, $lead]))) {
            throw ValidationException::withMessages(['schedule_followup' => 'You are not allowed to schedule a follow-up for this lead.']);
        }

        return DB::transaction(function () use ($meeting, $data, $actor, $lead, $outcome, $notes, $statusId, $scheduleFollowup) {
            $from = $meeting->status->value;
            $completedAt = now();

            $meeting->forceFill([
                'status' => MeetingStatus::Completed,
                'outcome' => $outcome,
                'outcome_notes' => $notes,
                'completed_at' => $completedAt,
                'completed_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();

            $attendance = $this->applyAttendance($meeting, (array) ($data['attendance'] ?? []));
            $this->reminders->cancel($meeting);

            if ($lead && $outcome?->countsAsContact()) {
                $this->sync->markContacted($lead, $completedAt);
            }

            $type = $meeting->type?->name ?? 'Meeting';
            if ($lead) {
                $this->activities->record($lead, ActivityService::MEETING_COMPLETED,
                    "{$type} completed".($outcome ? ". Outcome: {$outcome->label()}" : ''),
                    array_filter(['meeting_id' => $meeting->id, 'outcome' => $outcome?->value]));
            }
            $this->audit->log(AuditAction::MeetingCompleted, 'meetings', $meeting, "{$type} {$meeting->meeting_number} completed",
                ['status' => $from],
                array_filter(['status' => MeetingStatus::Completed->value, 'outcome' => $outcome?->value, 'attendance' => $attendance ?: null]));

            $followup = null;
            if ($scheduleFollowup) {
                $followupData = (array) ($data['followup'] ?? []);
                try {
                    $followup = $this->followups->create($lead, $followupData, $actor, (bool) ($followupData['confirm_duplicate'] ?? false));
                } catch (ValidationException $e) {
                    throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $key) => ["followup.{$key}" => $messages])->all());
                }
            }

            if ($statusId) {
                $this->leads->changeStatus($lead, $statusId, $actor, isset($data['lost_reason_id']) ? (int) $data['lost_reason_id'] : null, $data['lost_reason_notes'] ?? null);
            }

            $this->notifications->completed($meeting, $actor);

            return ['meeting' => $meeting, 'followup' => $followup];
        });
    }

    /**
     * @param  array<int|string, string>  $attendance  participant_id => attended|absent
     * @return array<int, string> applied changes
     */
    private function applyAttendance(Meeting $meeting, array $attendance): array
    {
        $allowed = [AttendanceStatus::Attended->value, AttendanceStatus::Absent->value];
        $wanted = collect($attendance)
            ->mapWithKeys(fn ($status, $id) => [(int) $id => (string) $status])
            ->filter(fn (string $status, int $id) => $id > 0 && in_array($status, $allowed, true));

        if ($wanted->isEmpty()) {
            return [];
        }

        $applied = [];
        foreach ($meeting->participants()->whereIn('id', $wanted->keys())->get() as $participant) {
            $participant->forceFill(['attendance_status' => $wanted[$participant->id]])->save();
            $applied[$participant->id] = $wanted[$participant->id];
        }
        $meeting->unsetRelation('participants');

        return $applied;
    }
}
