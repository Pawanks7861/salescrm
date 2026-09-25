<?php

namespace App\Services\Followups;

use App\Enums\AuditAction;
use App\Enums\FollowupOutcome;
use App\Enums\FollowupStatus;
use App\Enums\LeadPriority;
use App\Models\Followup;
use App\Models\FollowupType;
use App\Models\Lead;
use App\Models\User;
use App\Services\ActivityService;
use App\Services\AuditService;
use App\Services\Leads\LeadService;
use App\Services\SettingService;
use App\Support\CrmTime;
use App\Support\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Follow-up lifecycle. Every state change runs in one transaction together
 * with reminder planning, lead next_followup_at sync, activity and audit.
 *
 *   pending ──complete──▶ completed      (immutable)
 *   pending ──cancel────▶ cancelled      (immutable)
 *   pending ──reschedule▶ rescheduled    (immutable) + NEW pending record
 *
 * "Overdue" is never stored; see FollowupStatus.
 */
class FollowupService
{
    public const DUPLICATE_WINDOW_MINUTES = 5;

    public function __construct(
        private readonly FollowupVisibility $visibility,
        private readonly LeadFollowupSyncService $sync,
        private readonly FollowupReminderService $reminders,
        private readonly FollowupNotificationService $notifications,
        private readonly LeadService $leads,
        private readonly ActivityService $activities,
        private readonly AuditService $audit,
        private readonly SettingService $settings,
    ) {}

    /**
     * `$data` is validated input: followup_type_id, scheduled_date (Y-m-d),
     * scheduled_time (H:i), optional assigned_to, priority, reminder_minutes,
     * title, description.
     *
     * @throws ValidationException
     */
    public function create(Lead $lead, array $data, User $actor, bool $confirmDuplicate = false): Followup
    {
        return DB::transaction(function () use ($lead, $data, $actor, $confirmDuplicate) {
            $followup = $this->createRecord($lead, $data, $actor, $confirmDuplicate);
            $this->sync->sync($lead);

            return $followup;
        });
    }

    /** Edits descriptive fields of a pending follow-up. The schedule changes only via reschedule(). */
    public function update(Followup $followup, array $data, User $actor): Followup
    {
        $this->ensurePending($followup);
        $lead = $followup->lead;

        return DB::transaction(function () use ($followup, $data, $actor, $lead) {
            $followup->fill(collect($data)->only(['title', 'description'])->all());

            if (! empty($data['priority'])) {
                $followup->priority = LeadPriority::from($data['priority']);
            }

            if (! empty($data['followup_type_id']) && (int) $data['followup_type_id'] !== (int) $followup->followup_type_id) {
                $followup->followup_type_id = $this->resolveType($data['followup_type_id'])->id;
            }

            if (array_key_exists('reminder_minutes', $data)) {
                $followup->reminder_minutes_before = $this->reminderMinutes($data['reminder_minutes']);
            }

            if (! empty($data['assigned_to']) && (int) $data['assigned_to'] !== (int) $followup->assigned_to) {
                $assignee = $this->resolveAssignee($lead, (int) $data['assigned_to'], $actor);
                $followup->assigned_to = $assignee->id;
            }

            [$old, $new] = $this->audit->dirtyDiff($followup);

            if ($new === []) {
                return $followup;
            }

            $followup->updated_by = $actor->id;
            $followup->save();

            $this->audit->log(AuditAction::FollowupUpdated, 'followups', $followup, "Follow-up on {$lead->lead_number} updated", $old, $new);

            if (array_key_exists('reminder_minutes_before', $new) || array_key_exists('assigned_to', $new)) {
                $this->reminders->schedule($followup);
            }

            if (array_key_exists('assigned_to', $new)) {
                $followup->unsetRelation('assignee');
                $this->notifications->assigned($followup, $actor);
            }

            return $followup;
        });
    }

    /**
     * Completes a follow-up, optionally scheduling the next one and changing the
     * lead status (through LeadService) — all or nothing.
     *
     * @return array{followup: Followup, next: ?Followup}
     *
     * @throws ValidationException
     */
    public function complete(Followup $followup, array $data, User $actor): array
    {
        $this->ensurePending($followup);
        $lead = $followup->lead;

        $outcome = FollowupOutcome::tryFrom((string) ($data['outcome'] ?? ''));
        if (! $outcome && $this->settings->get('followup.require_outcome', true)) {
            throw ValidationException::withMessages(['outcome' => 'Select the outcome of this follow-up.']);
        }

        $statusId = ! empty($data['status_id']) ? (int) $data['status_id'] : null;
        if ($statusId && (! $actor->hasPermission(Permissions::LEAD_CHANGE_STATUS) || ! $actor->can('changeStatus', $lead))) {
            throw ValidationException::withMessages(['status_id' => 'You are not allowed to change the lead status.']);
        }

        return DB::transaction(function () use ($followup, $data, $actor, $lead, $outcome, $statusId) {
            $completedAt = now();

            $followup->forceFill([
                'status' => FollowupStatus::Completed,
                'outcome' => $outcome,
                'notes' => $data['notes'] ?? null,
                'next_action' => $data['next_action'] ?? null,
                'completed_at' => $completedAt,
                'completed_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();

            $this->reminders->cancel($followup);

            if ($outcome?->countsAsContact()) {
                $this->sync->markContacted($lead, $completedAt);
            }

            $type = $this->typeName($followup);
            $this->activities->record($lead, ActivityService::FOLLOWUP_COMPLETED,
                "Completed the {$type} follow-up".($outcome ? ". Outcome: {$outcome->label()}" : ''),
                array_filter(['followup_id' => $followup->id, 'outcome' => $outcome?->value]));
            $this->audit->log(AuditAction::FollowupCompleted, 'followups', $followup, "{$type} follow-up on {$lead->lead_number} completed",
                ['status' => FollowupStatus::Pending->value],
                array_filter(['status' => FollowupStatus::Completed->value, 'outcome' => $outcome?->value, 'next_action' => $data['next_action'] ?? null]));

            $next = null;
            if (! empty($data['schedule_next'])) {
                $nextData = (array) ($data['next'] ?? []);
                $next = $this->createRecord($lead, $nextData, $actor, (bool) ($nextData['confirm_duplicate'] ?? false), 'next.');
            }

            if ($statusId) {
                $this->leads->changeStatus($lead, $statusId, $actor, isset($data['lost_reason_id']) ? (int) $data['lost_reason_id'] : null, $data['lost_reason_notes'] ?? null);
            }

            $this->sync->sync($lead);

            return ['followup' => $followup, 'next' => $next];
        });
    }

    /**
     * Keeps the original as history (status = rescheduled) and creates a new
     * pending follow-up pointing back to it.
     *
     * @throws ValidationException
     */
    public function reschedule(Followup $followup, array $data, User $actor): Followup
    {
        $this->ensurePending($followup);
        $lead = $followup->lead;

        [$at, $tz] = $this->resolveSchedule($data, $actor);
        if ($at->equalTo($followup->scheduled_at)) {
            throw ValidationException::withMessages(['scheduled_time' => 'Choose a different date or time to reschedule.']);
        }

        $reason = trim((string) ($data['reason'] ?? '')) ?: null;

        return DB::transaction(function () use ($followup, $data, $actor, $lead, $at, $tz, $reason) {
            $oldAt = $followup->scheduled_at;

            $followup->forceFill([
                'status' => FollowupStatus::Rescheduled,
                'reschedule_reason' => $reason,
                'updated_by' => $actor->id,
            ])->save();
            $this->reminders->cancel($followup);

            $new = new Followup;
            $new->fill([
                'title' => $followup->title,
                'description' => $followup->description,
                'priority' => $followup->priority,
                'reminder_minutes_before' => array_key_exists('reminder_minutes', $data)
                    ? $this->reminderMinutes($data['reminder_minutes'])
                    : $followup->reminder_minutes_before,
            ]);
            $new->forceFill([
                'lead_id' => $lead->id,
                'assigned_to' => $followup->assigned_to,
                'followup_type_id' => $followup->followup_type_id,
                'scheduled_at' => $at,
                'timezone' => $tz,
                'status' => FollowupStatus::Pending,
                'rescheduled_from_id' => $followup->id,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();
            $new->setRelation('lead', $lead);

            $this->reminders->schedule($new);

            $type = $this->typeName($followup);
            $from = CrmTime::format($oldAt);
            $to = CrmTime::format($at);

            $this->activities->record($lead, ActivityService::FOLLOWUP_RESCHEDULED,
                "Rescheduled the {$type} follow-up from {$from} to {$to}".($reason ? ". Reason: {$reason}" : ''),
                ['followup_id' => $new->id, 'from' => $oldAt->toIso8601String(), 'to' => $at->toIso8601String()]);
            $this->audit->log(AuditAction::FollowupRescheduled, 'followups', $followup, "{$type} follow-up on {$lead->lead_number} rescheduled",
                ['status' => FollowupStatus::Pending->value, 'scheduled_at' => $oldAt->toDateTimeString()],
                array_filter(['status' => FollowupStatus::Rescheduled->value, 'scheduled_at' => $at->toDateTimeString(), 'new_followup_id' => $new->id, 'reason' => $reason]));

            $this->notifications->rescheduled($new, $actor);
            $this->sync->sync($lead);

            return $new;
        });
    }

    /** @throws ValidationException */
    public function cancel(Followup $followup, ?string $reason, User $actor): Followup
    {
        $this->ensurePending($followup);
        $lead = $followup->lead;

        $reason = trim((string) $reason) ?: null;
        if (! $reason && $this->settings->get('followup.require_cancellation_reason', true)) {
            throw ValidationException::withMessages(['reason' => 'Enter a reason for cancelling this follow-up.']);
        }

        return DB::transaction(function () use ($followup, $reason, $actor, $lead) {
            $followup->forceFill([
                'status' => FollowupStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancellation_reason' => $reason,
                'updated_by' => $actor->id,
            ])->save();
            $this->reminders->cancel($followup);

            $type = $this->typeName($followup);
            $this->activities->record($lead, ActivityService::FOLLOWUP_CANCELLED,
                "Cancelled the {$type} follow-up".($reason ? ". Reason: {$reason}" : ''),
                ['followup_id' => $followup->id]);
            $this->audit->log(AuditAction::FollowupCancelled, 'followups', $followup, "{$type} follow-up on {$lead->lead_number} cancelled",
                ['status' => FollowupStatus::Pending->value],
                array_filter(['status' => FollowupStatus::Cancelled->value, 'reason' => $reason]));

            $this->sync->sync($lead);

            return $followup;
        });
    }

    /** Soft delete (management clean-up). Normal users cancel instead. */
    public function delete(Followup $followup, User $actor): void
    {
        DB::transaction(function () use ($followup, $actor) {
            $followup->forceFill(['updated_by' => $actor->id])->save();
            $followup->delete();
            $this->reminders->cancel($followup);

            $this->audit->log(AuditAction::FollowupDeleted, 'followups', $followup, "{$this->typeName($followup)} follow-up on {$followup->lead?->lead_number} deleted", ['status' => $followup->status->value]);
            $this->sync->sync($followup->lead_id);
        });
    }

    public function restore(Followup $followup, User $actor): void
    {
        DB::transaction(function () use ($followup, $actor) {
            $followup->restore();
            $followup->forceFill(['updated_by' => $actor->id])->save();
            $this->reminders->schedule($followup);

            $this->audit->log(AuditAction::FollowupRestored, 'followups', $followup, "{$this->typeName($followup)} follow-up on {$followup->lead?->lead_number} restored", null, ['status' => $followup->status->value]);
            $this->sync->sync($followup->lead_id);
        });
    }

    /**
     * Resolves a wall-clock date/time in the CRM timezone to UTC and enforces
     * the no-past rule. Past times are rejected, never silently corrected.
     *
     * @return array{0: CarbonImmutable, 1: string}
     *
     * @throws ValidationException
     */
    public function resolveSchedule(array $data, User $actor, string $prefix = ''): array
    {
        $tz = CrmTime::tz();
        $at = CrmTime::toUtc((string) ($data['scheduled_date'] ?? ''), (string) ($data['scheduled_time'] ?? ''), $tz);

        if (! $at) {
            throw ValidationException::withMessages(["{$prefix}scheduled_time" => 'Enter a valid date and time.']);
        }

        if ($at->lt(now()->startOfMinute()) && ! $this->canSchedulePast($actor)) {
            throw ValidationException::withMessages(["{$prefix}scheduled_time" => 'Follow-ups cannot be scheduled in the past.']);
        }

        return [$at, $tz];
    }

    public function canSchedulePast(User $user): bool
    {
        return (bool) $this->settings->get('followup.allow_past', false) || $user->hasPermission(Permissions::FOLLOWUP_SCHEDULE_PAST);
    }

    /**
     * Default assignee for a new follow-up on `$lead`: the lead owner when the
     * actor may assign to them and they can access the lead, else the actor.
     */
    public function defaultAssignee(Lead $lead, User $actor): User
    {
        $owner = $lead->assigned_to ? User::query()->active()->find($lead->assigned_to) : null;

        if ($owner && $owner->id !== $actor->id && $this->mayAssignTo($actor, $owner, $lead)) {
            return $owner;
        }

        return $actor;
    }

    /** @throws ValidationException */
    private function createRecord(Lead $lead, array $data, User $actor, bool $confirmDuplicate, string $prefix = ''): Followup
    {
        [$at, $tz] = $this->resolveSchedule($data, $actor, $prefix);
        $type = $this->resolveType($data['followup_type_id'] ?? null, $prefix);
        $assignee = ! empty($data['assigned_to'])
            ? $this->resolveAssignee($lead, (int) $data['assigned_to'], $actor, $prefix)
            : $this->defaultAssignee($lead, $actor);

        if (! $confirmDuplicate && $this->hasNearbyDuplicate($lead, $assignee, $at)) {
            throw ValidationException::withMessages([
                "{$prefix}duplicate" => "{$assignee->name} already has a pending follow-up on this lead within ".self::DUPLICATE_WINDOW_MINUTES.' minutes of this time. Confirm to create it anyway.',
            ]);
        }

        $followup = new Followup;
        $followup->fill([
            'title' => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'priority' => LeadPriority::tryFrom((string) ($data['priority'] ?? '')) ?? LeadPriority::Medium,
            'reminder_minutes_before' => array_key_exists('reminder_minutes', $data)
                ? $this->reminderMinutes($data['reminder_minutes'])
                : $this->defaultReminderMinutes(),
        ]);
        $followup->forceFill([
            'lead_id' => $lead->id,
            'assigned_to' => $assignee->id,
            'followup_type_id' => $type->id,
            'scheduled_at' => $at,
            'timezone' => $tz,
            'status' => FollowupStatus::Pending,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ])->save();

        $followup->setRelation('lead', $lead);
        $followup->setRelation('type', $type);

        $this->reminders->schedule($followup);

        $when = CrmTime::format($at);
        $for = $assignee->id !== $actor->id ? " (assigned to {$assignee->name})" : '';
        $this->activities->record($lead, ActivityService::FOLLOWUP_CREATED,
            "Scheduled a {$type->name} follow-up for {$when}{$for}",
            ['followup_id' => $followup->id, 'scheduled_at' => $at->toIso8601String()]);
        $this->audit->log(AuditAction::FollowupCreated, 'followups', $followup, "{$type->name} follow-up scheduled on {$lead->lead_number}", null, [
            'lead_number' => $lead->lead_number,
            'type' => $type->name,
            'scheduled_at' => $at->toDateTimeString(),
            'timezone' => $tz,
            'assigned_to' => $assignee->id,
            'priority' => $followup->priority->value,
            'reminder_minutes_before' => $followup->reminder_minutes_before,
        ]);

        $this->notifications->assigned($followup, $actor);

        return $followup;
    }

    /** @throws ValidationException */
    private function resolveAssignee(Lead $lead, int $userId, User $actor, string $prefix = ''): User
    {
        if ($userId === $actor->id) {
            return $actor;
        }

        $allowed = $this->visibility->assignableUserIds($actor);
        if ($allowed !== null && ! in_array($userId, $allowed, true)) {
            throw ValidationException::withMessages(["{$prefix}assigned_to" => 'You cannot assign follow-ups to this user.']);
        }

        $user = User::query()->active()->find($userId);
        if (! $user) {
            throw ValidationException::withMessages(["{$prefix}assigned_to" => 'The selected user is not available.']);
        }

        if (! $this->visibility->canSeeLead($user, $lead)) {
            throw ValidationException::withMessages(["{$prefix}assigned_to" => "{$user->name} cannot access this lead."]);
        }

        return $user;
    }

    private function mayAssignTo(User $actor, User $target, Lead $lead): bool
    {
        $allowed = $this->visibility->assignableUserIds($actor);

        return ($allowed === null || in_array($target->id, $allowed, true)) && $this->visibility->canSeeLead($target, $lead);
    }

    /** @throws ValidationException */
    private function resolveType(mixed $id, string $prefix = ''): FollowupType
    {
        $type = $id ? FollowupType::query()->active()->find((int) $id) : null;

        if (! $type) {
            throw ValidationException::withMessages(["{$prefix}followup_type_id" => 'Select an active follow-up type.']);
        }

        return $type;
    }

    private function hasNearbyDuplicate(Lead $lead, User $assignee, CarbonImmutable $at): bool
    {
        return Followup::query()
            ->where('lead_id', $lead->id)
            ->where('assigned_to', $assignee->id)
            ->pending()
            ->whereBetween('scheduled_at', [$at->subMinutes(self::DUPLICATE_WINDOW_MINUTES), $at->addMinutes(self::DUPLICATE_WINDOW_MINUTES)])
            ->exists();
    }

    private function reminderMinutes(mixed $value): ?int
    {
        return $value === null || $value === '' || (int) $value < 0 ? null : (int) $value;
    }

    public function defaultReminderMinutes(): ?int
    {
        return $this->reminderMinutes($this->settings->get('followup.default_reminder_minutes', 15));
    }

    private function typeName(Followup $followup): string
    {
        return $followup->type?->name ?? 'follow-up';
    }

    /** @throws ValidationException */
    private function ensurePending(Followup $followup): void
    {
        if (! $followup->isPending()) {
            throw ValidationException::withMessages(['status' => 'Only pending follow-ups can be changed.']);
        }
    }
}
