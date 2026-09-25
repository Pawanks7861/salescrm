<?php

namespace App\Services\Leads;

use App\Enums\AssignmentType;
use App\Enums\RuleAssignment;
use App\Enums\RuleCondition;
use App\Models\Lead;
use App\Models\LeadAssignmentRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Evaluates active assignment rules in priority order (lowest number first,
 * then id). The first rule that matches AND can resolve a user wins.
 *
 * Rules always assign an individual user (specific user or user round robin).
 * Legacy team-based rules (assign to team, team round robin, "lead team"
 * condition) are deprecated: they are kept in the database but never run.
 * When nothing matches the lead stays unassigned (Admin / Super Admin only).
 */
class LeadAssignmentEngine
{
    public function __construct(private readonly LeadAssignmentService $assignments) {}

    /** Returns the rule that was applied, or null if no rule matched. */
    public function apply(Lead $lead, ?User $triggeredBy = null): ?LeadAssignmentRule
    {
        $rules = LeadAssignmentRule::query()->active()->ordered()->get()
            ->reject(fn (LeadAssignmentRule $rule) => $rule->isDeprecated());

        foreach ($rules as $rule) {
            if (! $this->matches($rule, $lead)) {
                continue;
            }

            $applied = DB::transaction(function () use ($rule, $lead, $triggeredBy) {
                $locked = LeadAssignmentRule::query()->whereKey($rule->id)->lockForUpdate()->first();
                if (! $locked || ! $locked->is_active || $locked->isDeprecated()) {
                    return false;
                }

                $user = $this->resolveTarget($locked);
                if ($user === null) {
                    return false;
                }

                $rotating = $locked->assignment_type === RuleAssignment::RoundRobin;

                if ($rotating) {
                    $locked->forceFill(['last_assigned_user_id' => $user->id])->saveQuietly();
                }

                $this->assignments->assign(
                    $lead,
                    $user,
                    $rotating ? AssignmentType::RoundRobin : AssignmentType::Rule,
                    $triggeredBy,
                    null,
                    $locked,
                );

                return true;
            });

            if ($applied) {
                return $rule;
            }
        }

        return null;
    }

    public function matches(LeadAssignmentRule $rule, Lead $lead): bool
    {
        $value = trim((string) $rule->condition_value);

        return match ($rule->condition_type) {
            RuleCondition::Any => true,
            RuleCondition::Source => $value !== '' && (string) $lead->source_id === $value,
            RuleCondition::Campaign => $value !== '' && (string) $lead->campaign_id === $value,
            RuleCondition::City => $value !== '' && mb_strtolower(trim((string) $lead->city)) === mb_strtolower($value),
            RuleCondition::State => $value !== '' && mb_strtolower(trim((string) $lead->state)) === mb_strtolower($value),
            RuleCondition::Team => false,
            RuleCondition::FacebookForm => $value !== '' && (string) $lead->facebook_form_id === $value,
        };
    }

    private function resolveTarget(LeadAssignmentRule $rule): ?User
    {
        return match ($rule->assignment_type) {
            RuleAssignment::User => User::query()->active()->whereKey($rule->assigned_user_id)->first(),
            RuleAssignment::RoundRobin => $this->roundRobin($rule),
            RuleAssignment::Team, RuleAssignment::TeamRoundRobin => null,
        };
    }

    private function roundRobin(LeadAssignmentRule $rule): ?User
    {
        $pool = array_values(array_map('intval', $rule->user_pool_json ?? []));
        $activeIds = User::query()->active()->whereIn('id', $pool)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $candidates = array_values(array_filter($pool, fn ($id) => in_array($id, $activeIds, true)));

        return $this->nextInRotation($candidates, $rule->last_assigned_user_id);
    }

    /** @param array<int> $candidates */
    private function nextInRotation(array $candidates, ?int $lastId): ?User
    {
        if ($candidates === []) {
            return null;
        }

        $index = $lastId === null ? false : array_search((int) $lastId, $candidates, true);
        $nextId = $index === false ? $candidates[0] : $candidates[($index + 1) % count($candidates)];

        return User::find($nextId);
    }
}
