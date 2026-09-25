<?php

namespace App\Services\Meetings;

use App\Enums\AttendanceStatus;
use App\Enums\AuditAction;
use App\Enums\LeadPriority;
use App\Enums\MeetingLocationType;
use App\Enums\MeetingOutcome;
use App\Enums\MeetingParticipantType;
use App\Enums\MeetingStatus;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\MeetingType;
use App\Models\User;
use App\Services\ActivityService;
use App\Services\AuditService;
use App\Services\SettingService;
use App\Support\CrmTime;
use App\Support\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Meeting lifecycle. Every state change runs in one transaction together with
 * participants, reminder planning, activity and audit; notifications are sent
 * after commit.
 *
 *   scheduled ─confirm─▶ confirmed ─start─▶ in_progress ─complete─▶ completed
 *   scheduled|confirmed ─reschedule─▶ rescheduled (history) + NEW meeting
 *   scheduled|confirmed|in_progress ─cancel─▶ cancelled | ─no-show─▶ no_show
 *
 * Completed, cancelled, no-show and rescheduled meetings are read-only.
 * Completion lives in MeetingCompletionService.
 */
class MeetingService
{
    public const MAX_DURATION_MINUTES = 1440;

    public function __construct(
        private readonly MeetingNumberService $numbers,
        private readonly MeetingParticipantService $participants,
        private readonly MeetingConflictService $conflicts,
        private readonly MeetingReminderService $reminders,
        private readonly MeetingNotificationService $notifications,
        private readonly ActivityService $activities,
        private readonly AuditService $audit,
        private readonly SettingService $settings,
    ) {}

    /**
     * `$data` is validated input: title, meeting_type_id, host_user_id,
     * scheduled_date, start_time, end_time, end_date?, timezone?, location_type,
     * location, address, meeting_url, agenda, description, priority, reminders[],
     * participant_user_ids[], include_lead, external_participants[].
     *
     * @throws ValidationException
     */
    public function create(?Lead $lead, array $data, User $actor, bool $overrideConflict = false): Meeting
    {
        $type = $this->resolveType($data['meeting_type_id'] ?? null);
        [$start, $end, $tz] = $this->resolveSchedule($data, $actor);
        $host = $this->participants->resolveHost($lead, isset($data['host_user_id']) ? (int) $data['host_user_id'] : null, $actor);
        $users = $this->participants->resolveUsers((array) ($data['participant_user_ids'] ?? []), $lead, $actor);

        $overridden = $this->conflicts->check($start, $end, $users->pluck('id')->push($host->id)->all(), $actor, $overrideConflict);

        return DB::transaction(function () use ($lead, $data, $actor, $type, $start, $end, $tz, $host, $users, $overridden) {
            $meeting = new Meeting;
            $meeting->fill($this->descriptive($data, $type));
            $meeting->reminder_offsets = MeetingReminderService::normalizeOffsets($data['reminders'] ?? $this->defaultReminders());
            $meeting->forceFill([
                'meeting_number' => $this->numbers->next(),
                'lead_id' => $lead?->id,
                'meeting_type_id' => $type->id,
                'host_user_id' => $host->id,
                'start_at' => $start,
                'end_at' => $end,
                'timezone' => $tz,
                'status' => MeetingStatus::Scheduled,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();

            $meeting->setRelation('lead', $lead);
            $meeting->setRelation('type', $type);

            $this->participants->attach($meeting, $users, (bool) ($data['include_lead'] ?? false), (array) ($data['external_participants'] ?? []));
            $this->reminders->schedule($meeting);

            if ($lead) {
                $for = $host->id !== $actor->id ? " hosted by {$host->name}" : '';
                $this->activities->record($lead, ActivityService::MEETING_CREATED,
                    "Scheduled a {$type->name} for ".CrmTime::format($start).$for,
                    ['meeting_id' => $meeting->id, 'start_at' => $start->toIso8601String()]);
            }

            $this->audit->log(AuditAction::MeetingCreated, 'meetings', $meeting, "{$type->name} {$meeting->meeting_number} scheduled".($lead ? " on {$lead->lead_number}" : ''), null, array_filter([
                'meeting_number' => $meeting->meeting_number,
                'lead_number' => $lead?->lead_number,
                'type' => $type->name,
                'start_at' => $start->toDateTimeString(),
                'end_at' => $end->toDateTimeString(),
                'timezone' => $tz,
                'host_user_id' => $host->id,
                'participants' => $meeting->participants()->count(),
                'reminders' => $meeting->reminder_offsets,
            ], fn ($v) => $v !== null));

            $this->auditOverride($meeting, $overridden);

            if ($host->id !== $actor->id) {
                $this->notifications->assigned($meeting, $actor);
            }
            $this->notifications->invited($meeting, $users->pluck('id')->all(), $actor);

            return $meeting;
        });
    }

    /**
     * Edits descriptive fields, type, host and reminders of an upcoming meeting.
     * The schedule changes only via reschedule(); participants via their endpoints.
     *
     * @throws ValidationException
     */
    public function update(Meeting $meeting, array $data, User $actor, bool $overrideConflict = false): Meeting
    {
        $this->ensureUpcoming($meeting);
        $lead = $meeting->lead;

        $type = ! empty($data['meeting_type_id']) && (int) $data['meeting_type_id'] !== (int) $meeting->meeting_type_id
            ? $this->resolveType($data['meeting_type_id'])
            : null;

        $newHost = ! empty($data['host_user_id']) && (int) $data['host_user_id'] !== (int) $meeting->host_user_id
            ? $this->participants->resolveHost($lead, (int) $data['host_user_id'], $actor)
            : null;

        $overridden = $newHost
            ? $this->conflicts->check($meeting->start_at, $meeting->end_at, [$newHost->id], $actor, $overrideConflict, [$meeting->id])
            : collect();

        return DB::transaction(function () use ($meeting, $data, $actor, $type, $newHost, $overridden) {
            $meeting->fill(collect($this->descriptive($data, $type ?? $meeting->type, $meeting))->all());

            if ($type) {
                $meeting->meeting_type_id = $type->id;
            }

            if (array_key_exists('reminders', $data)) {
                $offsets = MeetingReminderService::normalizeOffsets($data['reminders']);
                if ($offsets !== MeetingReminderService::normalizeOffsets($meeting->reminder_offsets ?? [])) {
                    $meeting->reminder_offsets = $offsets;
                }
            }

            $oldHost = $meeting->host_user_id;
            if ($newHost) {
                $meeting->host_user_id = $newHost->id;
                // A new host is no longer a separate participant.
                $meeting->participants()->where('participant_type', MeetingParticipantType::User->value)->where('user_id', $newHost->id)->delete();
            }

            [$old, $new] = $this->audit->dirtyDiff($meeting);
            if ($new === []) {
                return $meeting;
            }

            $meeting->updated_by = $actor->id;
            $meeting->save();

            $this->audit->log(AuditAction::MeetingUpdated, 'meetings', $meeting, "{$meeting->meeting_number} updated", $old, $new);

            if ($newHost) {
                $meeting->unsetRelation('host');
                $meeting->unsetRelation('participants');
                $this->audit->log(AuditAction::MeetingAssigned, 'meetings', $meeting, "{$meeting->meeting_number} host changed to {$newHost->name}",
                    ['host_user_id' => $oldHost], ['host_user_id' => $newHost->id]);
                $this->auditOverride($meeting, $overridden);
                $this->notifications->assigned($meeting, $actor);
            }

            if ($newHost || array_key_exists('reminder_offsets', $new)) {
                $this->reminders->schedule($meeting);
            }

            if ($type) {
                $meeting->setRelation('type', $type);
            }

            return $meeting;
        });
    }

    /** @throws ValidationException */
    public function confirm(Meeting $meeting, User $actor): Meeting
    {
        if ($meeting->status !== MeetingStatus::Scheduled) {
            throw ValidationException::withMessages(['status' => 'Only scheduled meetings can be confirmed.']);
        }

        return DB::transaction(function () use ($meeting, $actor) {
            $meeting->forceFill(['status' => MeetingStatus::Confirmed, 'confirmed_at' => now(), 'updated_by' => $actor->id])->save();

            if ($meeting->lead) {
                $this->activities->record($meeting->lead, ActivityService::MEETING_CONFIRMED,
                    "Confirmed the {$this->typeName($meeting)} on ".CrmTime::format($meeting->start_at),
                    ['meeting_id' => $meeting->id]);
            }
            $this->audit->log(AuditAction::MeetingConfirmed, 'meetings', $meeting, "{$meeting->meeting_number} confirmed",
                ['status' => MeetingStatus::Scheduled->value], ['status' => MeetingStatus::Confirmed->value]);

            return $meeting;
        });
    }

    /** Optional step; meetings can be completed directly. */
    public function start(Meeting $meeting, User $actor): Meeting
    {
        if (! $meeting->isUpcoming()) {
            throw ValidationException::withMessages(['status' => 'Only scheduled or confirmed meetings can be started.']);
        }

        return DB::transaction(function () use ($meeting, $actor) {
            $from = $meeting->status->value;
            $meeting->forceFill(['status' => MeetingStatus::InProgress, 'started_at' => now(), 'updated_by' => $actor->id])->save();
            $this->reminders->cancel($meeting);

            $this->audit->log(AuditAction::MeetingStarted, 'meetings', $meeting, "{$meeting->meeting_number} started",
                ['status' => $from], ['status' => MeetingStatus::InProgress->value]);

            return $meeting;
        });
    }

    /**
     * Keeps the original as history (status = rescheduled) and creates a new
     * meeting pointing back to it, with the same participants.
     *
     * @throws ValidationException
     */
    public function reschedule(Meeting $meeting, array $data, User $actor, bool $overrideConflict = false): Meeting
    {
        $this->ensureUpcoming($meeting);
        $lead = $meeting->lead;

        [$start, $end, $tz] = $this->resolveSchedule($data, $actor);
        if ($start->equalTo($meeting->start_at) && $end->equalTo($meeting->end_at)) {
            throw ValidationException::withMessages(['start_time' => 'Choose a different date or time to reschedule.']);
        }

        $overridden = $this->conflicts->check($start, $end, $meeting->attendeeUserIds(), $actor, $overrideConflict, [$meeting->id]);
        $reason = trim((string) ($data['reason'] ?? '')) ?: null;

        return DB::transaction(function () use ($meeting, $data, $actor, $lead, $start, $end, $tz, $reason, $overridden) {
            $oldStart = $meeting->start_at;
            $oldStatus = $meeting->status->value;

            $meeting->forceFill([
                'status' => MeetingStatus::Rescheduled,
                'reschedule_reason' => $reason,
                'updated_by' => $actor->id,
            ])->save();
            $this->reminders->cancel($meeting);

            $new = new Meeting;
            $new->fill([
                'title' => $meeting->title,
                'description' => $meeting->description,
                'agenda' => $meeting->agenda,
                'location_type' => $meeting->location_type,
                'location' => $meeting->location,
                'address' => $meeting->address,
                'meeting_url' => $meeting->meeting_url,
                'priority' => $meeting->priority,
                'reminder_offsets' => array_key_exists('reminders', $data)
                    ? MeetingReminderService::normalizeOffsets($data['reminders'])
                    : ($meeting->reminder_offsets ?? []),
            ]);
            $new->forceFill([
                'meeting_number' => $this->numbers->next(),
                'lead_id' => $meeting->lead_id,
                'meeting_type_id' => $meeting->meeting_type_id,
                'host_user_id' => $meeting->host_user_id,
                'start_at' => $start,
                'end_at' => $end,
                'timezone' => $tz,
                'status' => MeetingStatus::Scheduled,
                'rescheduled_from_id' => $meeting->id,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();
            $new->setRelation('lead', $lead);
            $new->setRelation('type', $meeting->type);

            $this->participants->copy($meeting, $new);
            $this->reminders->schedule($new);

            $from = CrmTime::format($oldStart);
            $to = CrmTime::format($start);

            if ($lead) {
                $this->activities->record($lead, ActivityService::MEETING_RESCHEDULED,
                    "Rescheduled the {$this->typeName($meeting)} from {$from} to {$to}".($reason ? ". Reason: {$reason}" : ''),
                    ['meeting_id' => $new->id, 'from' => $oldStart->toIso8601String(), 'to' => $start->toIso8601String()]);
            }
            $this->audit->log(AuditAction::MeetingRescheduled, 'meetings', $meeting, "{$meeting->meeting_number} rescheduled to {$new->meeting_number}",
                ['status' => $oldStatus, 'start_at' => $oldStart->toDateTimeString(), 'end_at' => $meeting->end_at->toDateTimeString()],
                array_filter(['status' => MeetingStatus::Rescheduled->value, 'start_at' => $start->toDateTimeString(), 'end_at' => $end->toDateTimeString(), 'new_meeting_id' => $new->id, 'reason' => $reason]));

            $this->auditOverride($new, $overridden);
            $this->notifications->rescheduled($new, $actor);

            return $new;
        });
    }

    /** @throws ValidationException */
    public function cancel(Meeting $meeting, ?string $reason, User $actor): Meeting
    {
        $this->ensureOpen($meeting);

        $reason = trim((string) $reason) ?: null;
        if (! $reason && $this->settings->get('meeting.require_cancellation_reason', true)) {
            throw ValidationException::withMessages(['reason' => 'Enter a reason for cancelling this meeting.']);
        }

        return DB::transaction(function () use ($meeting, $reason, $actor) {
            $from = $meeting->status->value;
            $meeting->forceFill([
                'status' => MeetingStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancellation_reason' => $reason,
                'updated_by' => $actor->id,
            ])->save();
            $this->reminders->cancel($meeting);

            if ($meeting->lead) {
                $this->activities->record($meeting->lead, ActivityService::MEETING_CANCELLED,
                    "Cancelled the {$this->typeName($meeting)} scheduled for ".CrmTime::format($meeting->start_at).($reason ? ". Reason: {$reason}" : ''),
                    ['meeting_id' => $meeting->id]);
            }
            $this->audit->log(AuditAction::MeetingCancelled, 'meetings', $meeting, "{$meeting->meeting_number} cancelled",
                ['status' => $from], array_filter(['status' => MeetingStatus::Cancelled->value, 'reason' => $reason]));

            $this->notifications->cancelled($meeting, $actor);

            return $meeting;
        });
    }

    /**
     * Records that the meeting did not happen as planned. `absent_participant_ids`
     * marks who did not attend. Never changes the lead status.
     *
     * @throws ValidationException
     */
    public function markNoShow(Meeting $meeting, array $data, User $actor): Meeting
    {
        $this->ensureOpen($meeting);

        return DB::transaction(function () use ($meeting, $data, $actor) {
            $from = $meeting->status->value;
            $absent = $this->participantIds($meeting, (array) ($data['absent_participant_ids'] ?? []));

            $meeting->forceFill([
                'status' => MeetingStatus::NoShow,
                'outcome' => MeetingOutcome::NoShow,
                'outcome_notes' => ($data['notes'] ?? null) ?: null,
                'updated_by' => $actor->id,
            ])->save();

            if ($absent !== []) {
                $meeting->participants()->whereIn('id', $absent)->update(['attendance_status' => AttendanceStatus::Absent->value, 'updated_at' => now()]);
            }
            $this->reminders->cancel($meeting);

            if ($meeting->lead) {
                $this->activities->record($meeting->lead, ActivityService::MEETING_NO_SHOW,
                    "{$this->typeName($meeting)} on ".CrmTime::format($meeting->start_at).' marked as no-show',
                    ['meeting_id' => $meeting->id]);
            }
            $this->audit->log(AuditAction::MeetingNoShow, 'meetings', $meeting, "{$meeting->meeting_number} marked no-show",
                ['status' => $from], array_filter(['status' => MeetingStatus::NoShow->value, 'absent_participant_ids' => $absent ?: null]));

            return $meeting;
        });
    }

    /** Soft delete (management clean-up). Normal users cancel instead. */
    public function delete(Meeting $meeting, User $actor): void
    {
        DB::transaction(function () use ($meeting, $actor) {
            $meeting->forceFill(['updated_by' => $actor->id])->save();
            $meeting->delete();
            $this->reminders->cancel($meeting);

            $this->audit->log(AuditAction::MeetingDeleted, 'meetings', $meeting, "{$meeting->meeting_number} deleted", ['status' => $meeting->status->value]);
        });
    }

    public function restore(Meeting $meeting, User $actor): void
    {
        DB::transaction(function () use ($meeting, $actor) {
            $meeting->restore();
            $meeting->forceFill(['updated_by' => $actor->id])->save();
            $this->reminders->schedule($meeting);

            $this->audit->log(AuditAction::MeetingRestored, 'meetings', $meeting, "{$meeting->meeting_number} restored", null, ['status' => $meeting->status->value]);
        });
    }

    /**
     * Adds one participant. `$data`: type (user|lead|external), user_id, name, email, phone.
     *
     * @throws ValidationException
     */
    public function addParticipant(Meeting $meeting, array $data, User $actor, bool $overrideConflict = false): MeetingParticipant
    {
        $this->ensureUpcoming($meeting);
        $type = MeetingParticipantType::tryFrom((string) ($data['type'] ?? ''));

        $users = collect();
        $overridden = collect();

        if ($type === MeetingParticipantType::User) {
            $users = $this->participants->resolveUsers([(int) ($data['user_id'] ?? 0)], $meeting->lead, $actor, 'user_id');
            $user = $users->first();
            if ((int) $user->id === (int) $meeting->host_user_id || $meeting->participants()->where('user_id', $user->id)->exists()) {
                throw ValidationException::withMessages(['user_id' => "{$user->name} is already part of this meeting."]);
            }
            $overridden = $this->conflicts->check($meeting->start_at, $meeting->end_at, [$user->id], $actor, $overrideConflict, [$meeting->id]);
        } elseif ($type === MeetingParticipantType::Lead) {
            if (! $meeting->lead) {
                throw ValidationException::withMessages(['type' => 'This meeting is not linked to a lead.']);
            }
            if ($meeting->participants()->where('lead_id', $meeting->lead_id)->exists()) {
                throw ValidationException::withMessages(['type' => 'The lead is already a participant.']);
            }
        } elseif ($type !== MeetingParticipantType::External) {
            throw ValidationException::withMessages(['type' => 'Select a participant type.']);
        }

        return DB::transaction(function () use ($meeting, $data, $actor, $type, $users, $overridden) {
            $existing = $meeting->participants()->pluck('id')->all();

            $this->participants->attach(
                $meeting,
                $users,
                $type === MeetingParticipantType::Lead,
                $type === MeetingParticipantType::External ? [$data] : [],
            );

            $participant = $meeting->participants()->whereNotIn('id', $existing)->latest('id')->firstOrFail();
            $meeting->forceFill(['updated_by' => $actor->id])->save();

            $this->participants->auditAdded($meeting, $participant);
            $this->auditOverride($meeting, $overridden);

            if ($type === MeetingParticipantType::User) {
                $this->reminders->schedule($meeting);
                $this->notifications->invited($meeting, [(int) $participant->user_id], $actor);
            }

            return $participant;
        });
    }

    /** @throws ValidationException */
    public function removeParticipant(Meeting $meeting, MeetingParticipant $participant, User $actor): void
    {
        $this->ensureUpcoming($meeting);

        DB::transaction(function () use ($meeting, $participant, $actor) {
            $participant->delete();
            $meeting->forceFill(['updated_by' => $actor->id])->save();
            $meeting->unsetRelation('participants');

            $this->participants->auditRemoved($meeting, $participant);

            if ($participant->participant_type === MeetingParticipantType::User) {
                $this->reminders->schedule($meeting);
            }
        });
    }

    /**
     * An internal participant confirms or declines their own attendance.
     *
     * @throws ValidationException
     */
    public function respond(Meeting $meeting, MeetingParticipant $participant, AttendanceStatus $status, User $actor): MeetingParticipant
    {
        $this->ensureUpcoming($meeting);

        if (! in_array($status, [AttendanceStatus::Confirmed, AttendanceStatus::Declined], true)) {
            throw ValidationException::withMessages(['attendance_status' => 'Choose to confirm or decline.']);
        }

        return DB::transaction(function () use ($meeting, $participant, $status, $actor) {
            $from = $participant->attendance_status->value;
            $participant->forceFill(['attendance_status' => $status])->save();
            $meeting->unsetRelation('participants');

            $this->audit->log(AuditAction::MeetingAttendanceUpdated, 'meetings', $meeting,
                "{$actor->name} {$status->value} attendance for {$meeting->meeting_number}",
                ['attendance_status' => $from], ['attendance_status' => $status->value, 'participant_id' => $participant->id]);

            $this->reminders->schedule($meeting);

            return $participant;
        });
    }

    /**
     * Resolves wall-clock date / start / end in the chosen timezone (default:
     * CRM timezone) to UTC. Rejects invalid, inverted, over-long and — without
     * permission — past meetings. Never silently corrects values.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}
     *
     * @throws ValidationException
     */
    public function resolveSchedule(array $data, User $actor): array
    {
        $tz = (string) ($data['timezone'] ?? '');
        $tz = $tz !== '' && in_array($tz, timezone_identifiers_list(), true) ? $tz : CrmTime::tz();

        $date = (string) ($data['scheduled_date'] ?? '');
        $start = CrmTime::toUtc($date, (string) ($data['start_time'] ?? ''), $tz);
        if (! $start) {
            throw ValidationException::withMessages(['start_time' => 'Enter a valid date and start time.']);
        }

        $end = CrmTime::toUtc((string) (($data['end_date'] ?? null) ?: $date), (string) ($data['end_time'] ?? ''), $tz);
        if (! $end) {
            throw ValidationException::withMessages(['end_time' => 'Enter a valid end time.']);
        }

        if ($end->lte($start)) {
            throw ValidationException::withMessages(['end_time' => 'The end time must be after the start time.']);
        }

        if ($start->diffInMinutes($end) > self::MAX_DURATION_MINUTES) {
            throw ValidationException::withMessages(['end_time' => 'A meeting cannot be longer than 24 hours.']);
        }

        if ($start->lt(now()->startOfMinute()) && ! $this->canSchedulePast($actor)) {
            throw ValidationException::withMessages(['start_time' => 'Meetings cannot be scheduled in the past.']);
        }

        return [$start, $end, $tz];
    }

    public function canSchedulePast(User $user): bool
    {
        return (bool) $this->settings->get('meeting.allow_past', false) || $user->hasPermission(Permissions::MEETING_SCHEDULE_PAST);
    }

    /** @return array<int> */
    public function defaultReminders(): array
    {
        $minutes = (int) $this->settings->get('meeting.default_reminder_minutes', 30);

        return $minutes < 0 ? [] : [$minutes];
    }

    /** Default duration for a type (falls back to the global setting). */
    public function defaultDuration(?MeetingType $type): int
    {
        return (int) ($type?->default_duration_minutes ?: $this->settings->get('meeting.default_duration_minutes', 30));
    }

    /** @param Collection<int, array{user_id: int, meeting: Meeting}> $overridden */
    private function auditOverride(Meeting $meeting, Collection $overridden): void
    {
        if ($overridden->isEmpty()) {
            return;
        }

        $this->audit->log(AuditAction::MeetingConflictOverridden, 'meetings', $meeting, "Calendar conflict overridden for {$meeting->meeting_number}", null, [
            'conflicts' => $overridden->map(fn (array $c) => ['user_id' => $c['user_id'], 'meeting_id' => $c['meeting']->id])->values()->all(),
        ]);
    }

    /** Descriptive (fillable) attributes from validated input. */
    private function descriptive(array $data, ?MeetingType $type, ?Meeting $existing = null): array
    {
        $fields = collect($data)->only(['title', 'description', 'agenda', 'location', 'address', 'meeting_url'])
            ->map(fn ($v) => is_string($v) ? (trim($v) ?: null) : $v);

        if (array_key_exists('priority', $data) || ! $existing) {
            $fields['priority'] = LeadPriority::tryFrom((string) ($data['priority'] ?? '')) ?? ($existing?->priority ?? LeadPriority::Medium);
        }

        if (array_key_exists('location_type', $data) || ! $existing) {
            $fields['location_type'] = MeetingLocationType::tryFrom((string) ($data['location_type'] ?? ''))
                ?? $existing?->location_type
                ?? MeetingLocationType::forMode($type?->location_mode?->value ?? 'flexible');
        }

        if (! $existing && empty($fields['title'])) {
            $fields['title'] = $type?->name ?? 'Meeting';
        }

        return $fields->all();
    }

    /** @throws ValidationException */
    private function resolveType(mixed $id): MeetingType
    {
        $type = $id ? MeetingType::query()->active()->find((int) $id) : null;

        if (! $type) {
            throw ValidationException::withMessages(['meeting_type_id' => 'Select an active meeting type.']);
        }

        return $type;
    }

    /** @return array<int> ids that belong to this meeting */
    private function participantIds(Meeting $meeting, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        return $ids === [] ? [] : $meeting->participants()->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function typeName(Meeting $meeting): string
    {
        return $meeting->type?->name ?? 'meeting';
    }

    /** @throws ValidationException */
    private function ensureUpcoming(Meeting $meeting): void
    {
        if ($meeting->trashed() || ! $meeting->isUpcoming()) {
            throw ValidationException::withMessages(['status' => 'Only scheduled or confirmed meetings can be changed.']);
        }
    }

    /** @throws ValidationException */
    private function ensureOpen(Meeting $meeting): void
    {
        if ($meeting->trashed() || ! $meeting->isOpen()) {
            throw ValidationException::withMessages(['status' => 'This meeting is closed and can no longer be changed.']);
        }
    }
}
