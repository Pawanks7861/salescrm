<?php

namespace App\Services\Leads;

use App\Enums\AssignmentType;
use App\Enums\AuditAction;
use App\Events\LeadAssigned;
use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\LeadAssignmentRule;
use App\Models\User;
use App\Services\ActivityService;
use App\Services\AuditService;
use App\Support\Permissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only code path that changes lead ownership. Every change writes
 * assignment history, a timeline activity and an audit entry.
 */
class LeadAssignmentService
{
    public const MAX_BULK = 100;

    public function __construct(
        private readonly LeadVisibility $visibility,
        private readonly ActivityService $activities,
        private readonly AuditService $audit,
    ) {}

    /**
     * Manual (user-driven) assignment with full scope enforcement. The target
     * user always comes from a validated id re-checked here, never trusted.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function assignManually(Lead $lead, User $actor, int $toUserId, ?string $reason = null): bool
    {
        $required = $lead->assigned_to === null ? Permissions::LEAD_ASSIGN : Permissions::LEAD_REASSIGN;

        if (! $actor->hasPermission($required) || ! $this->visibility->canView($actor, $lead)) {
            throw new AuthorizationException('You are not allowed to assign this lead.');
        }

        $target = $this->resolveAssignableUser($actor, $toUserId);

        return $this->assign($lead, $target, AssignmentType::Manual, $actor, $reason);
    }

    /**
     * Assign several leads to one user. Super Admin and Admin only. Every id
     * is checked before anything is written, and each change still goes through
     * the normal history, activity, audit and notification path.
     *
     * @param  array<int|string>  $leadIds
     * @return int number of leads whose owner actually changed
     *
     * @throws AuthorizationException|ValidationException
     */
    public function assignMany(User $actor, array $leadIds, int $toUserId, ?string $reason = null): int
    {
        if (! $actor->isAdmin()) {
            throw new AuthorizationException('You are not allowed to assign these leads.');
        }

        $ids = array_values(array_unique(array_map('intval', $leadIds)));

        if (count($ids) > self::MAX_BULK) {
            throw ValidationException::withMessages(['lead_ids' => 'Select at most '.self::MAX_BULK.' leads at a time.']);
        }

        $leads = Lead::query()->visibleTo($actor)->whereIn('id', $ids)->get();

        if ($leads->count() !== count($ids)) {
            throw ValidationException::withMessages(['lead_ids' => 'One or more selected leads do not exist or are not available to you.']);
        }

        foreach ($leads as $lead) {
            $required = $lead->assigned_to === null ? Permissions::LEAD_ASSIGN : Permissions::LEAD_REASSIGN;

            if (! $actor->hasPermission($required)) {
                throw new AuthorizationException('You are not allowed to assign this lead.');
            }
        }

        $target = $this->resolveAssignableUser($actor, $toUserId);
        $changed = 0;

        foreach ($leads as $lead) {
            if ($this->assign($lead, $target, AssignmentType::Manual, $actor, $reason)) {
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * @throws ValidationException
     */
    public function resolveAssignableUser(User $actor, int $userId): User
    {
        $allowed = $this->visibility->assignableUserIds($actor);

        $target = User::query()->active()->whereKey($userId)->first();

        if (! $target || ($allowed !== null && ! in_array($target->id, $allowed, true))) {
            throw ValidationException::withMessages(['assigned_to' => 'You cannot assign leads to the selected user.']);
        }

        return $target;
    }

    /**
     * Low-level assignment used by manual, rule and inbound flows. Callers are
     * responsible for authorisation. Returns false when nothing changed.
     * Ownership is the assigned user only; the legacy leads.team_id column is
     * neither written nor read.
     */
    public function assign(
        Lead $lead,
        ?User $to,
        AssignmentType $type,
        ?User $by = null,
        ?string $reason = null,
        ?LeadAssignmentRule $rule = null,
    ): bool {
        $fromUserId = $lead->assigned_to !== null ? (int) $lead->assigned_to : null;
        $toUserId = $to?->id;

        if ($fromUserId === $toUserId) {
            return false;
        }

        DB::transaction(function () use ($lead, $to, $toUserId, $type, $by, $reason, $rule, $fromUserId) {
            $lead->forceFill([
                'assigned_to' => $toUserId,
                'updated_by' => $by?->id ?? $lead->updated_by,
            ])->save();

            LeadAssignment::create([
                'lead_id' => $lead->id,
                'from_user_id' => $fromUserId,
                'to_user_id' => $toUserId,
                'assigned_by' => $by?->id,
                'assignment_type' => $type,
                'rule_id' => $rule?->id,
                'reason' => $reason,
            ]);

            $fromName = $fromUserId ? User::withTrashed()->whereKey($fromUserId)->value('name') : null;
            $target = $to?->name ?? 'nobody';
            $how = $rule ? " via rule \"{$rule->name}\"" : '';

            $this->activities->record(
                $lead,
                ActivityService::LEAD_ASSIGNED,
                $fromName ? "Reassigned from {$fromName} to {$target}{$how}" : "Assigned to {$target}{$how}",
                ['from_user_id' => $fromUserId, 'to_user_id' => $toUserId, 'type' => $type->value, 'rule_id' => $rule?->id],
                $by?->id,
            );

            $this->audit->log(
                $fromUserId === null ? AuditAction::LeadAssigned : AuditAction::LeadReassigned,
                'leads',
                $lead,
                "Lead {$lead->lead_number} ".($fromUserId === null ? 'assigned' : 'reassigned')." ({$type->value})",
                ['assigned_to' => $fromUserId],
                ['assigned_to' => $toUserId, 'rule_id' => $rule?->id, 'reason' => $reason],
                $by?->id,
            );
        });

        LeadAssigned::dispatch($lead, $fromUserId, $toUserId, $by?->id);

        return true;
    }
}
