<?php

namespace App\Services\Leads;

use App\Enums\AuditAction;
use App\Models\LeadAssignmentRule;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;

class AssignmentRuleService
{
    public function __construct(private readonly AuditService $audit) {}

    public function save(LeadAssignmentRule $rule, array $data, User $actor): LeadAssignmentRule
    {
        return DB::transaction(function () use ($rule, $data, $actor) {
            $isNew = ! $rule->exists;
            $rule->fill($data);

            if ($rule->isDirty('user_pool_json') || $rule->isDirty('assigned_team_id') || $rule->isDirty('assignment_type')) {
                $rule->last_assigned_user_id = null;
            }

            if ($isNew) {
                $rule->created_by = $actor->id;
            }
            $rule->updated_by = $actor->id;

            [$old, $new] = $this->audit->dirtyDiff($rule, ['updated_at', 'updated_by', 'created_by', 'last_assigned_user_id']);
            $rule->save();

            if ($isNew || $new !== []) {
                $this->audit->log(
                    $isNew ? AuditAction::AssignmentRuleCreated : AuditAction::AssignmentRuleUpdated,
                    'assignment_rules',
                    $rule,
                    "Assignment rule \"{$rule->name}\" ".($isNew ? 'created' : 'updated'),
                    $isNew ? null : $old,
                    $new,
                );
            }

            return $rule;
        });
    }

    public function setActive(LeadAssignmentRule $rule, bool $active, User $actor): void
    {
        if ($rule->is_active === $active) {
            return;
        }

        $rule->forceFill(['is_active' => $active, 'updated_by' => $actor->id])->save();

        $this->audit->log(
            $active ? AuditAction::AssignmentRuleEnabled : AuditAction::AssignmentRuleDisabled,
            'assignment_rules',
            $rule,
            "Assignment rule \"{$rule->name}\" ".($active ? 'enabled' : 'disabled'),
        );
    }

    public function delete(LeadAssignmentRule $rule, User $actor): void
    {
        DB::transaction(function () use ($rule, $actor) {
            $rule->forceFill(['is_active' => false, 'updated_by' => $actor->id])->save();
            $rule->delete();
            $this->audit->log(AuditAction::AssignmentRuleDeleted, 'assignment_rules', $rule, "Assignment rule \"{$rule->name}\" deleted");
        });
    }

    /** @param array<int> $orderedIds highest priority first */
    public function reorder(array $orderedIds, User $actor): void
    {
        DB::transaction(function () use ($orderedIds, $actor) {
            foreach (array_values($orderedIds) as $position => $id) {
                LeadAssignmentRule::query()->whereKey($id)->update(['priority' => ($position + 1) * 10, 'updated_by' => $actor->id]);
            }

            $this->audit->log(AuditAction::AssignmentRuleUpdated, 'assignment_rules', null, 'Assignment rule priorities reordered', null, ['order' => array_values($orderedIds)]);
        });
    }
}
