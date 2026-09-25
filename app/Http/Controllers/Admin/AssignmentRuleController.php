<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RuleAssignment;
use App\Enums\RuleCondition;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\FacebookForm;
use App\Models\LeadAssignmentRule;
use App\Models\LeadSource;
use App\Models\User;
use App\Services\Leads\AssignmentRuleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Route-gated by lead.assignment_rules. */
class AssignmentRuleController extends Controller
{
    public function __construct(private readonly AssignmentRuleService $rules) {}

    public function index(): Response
    {
        return Inertia::render('Admin/AssignmentRules/Index', [
            'rules' => LeadAssignmentRule::query()->ordered()->with(['assignedUser:id,name'])->get()
                ->map(fn (LeadAssignmentRule $r) => [
                    ...$r->only('id', 'name', 'condition_value', 'assigned_user_id', 'priority', 'is_active'),
                    'condition_type' => $r->condition_type->value,
                    'condition_label' => $r->condition_type->label(),
                    'assignment_type' => $r->assignment_type->value,
                    'assignment_label' => $r->assignment_type->label(),
                    'deprecated' => $r->isDeprecated(),
                    'user_pool' => array_map('intval', $r->user_pool_json ?? []),
                    'assigned_user' => $r->assignedUser?->name,
                ]),
            'conditions' => array_map(fn (RuleCondition $c) => ['value' => $c->value, 'label' => $c->label()], RuleCondition::supported()),
            'assignmentTypes' => array_map(fn (RuleAssignment $a) => ['value' => $a->value, 'label' => $a->label()], RuleAssignment::supported()),
            'users' => User::active()->orderBy('name')->get(['id', 'name', 'designation']),
            'sources' => LeadSource::query()->ordered()->get(['id', 'name']),
            'campaigns' => Campaign::query()->orderBy('name')->get(['id', 'name']),
            'facebookForms' => FacebookForm::query()->with('page:id,page_name')->orderBy('form_name')->get(['id', 'facebook_page_id', 'form_id', 'form_name'])
                ->map(fn (FacebookForm $f) => ['value' => $f->form_id, 'label' => $f->form_name.($f->page ? " · {$f->page->page_name}" : '')])
                ->unique('value')->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $rule = $this->rules->save(new LeadAssignmentRule, $this->validated($request), $request->user());

        return back()->with('success', "Rule \"{$rule->name}\" created.");
    }

    public function update(Request $request, LeadAssignmentRule $rule): RedirectResponse
    {
        $this->rules->save($rule, $this->validated($request), $request->user());

        return back()->with('success', "Rule \"{$rule->name}\" updated.");
    }

    public function toggle(Request $request, LeadAssignmentRule $rule): RedirectResponse
    {
        if (! $rule->is_active && $rule->isDeprecated()) {
            throw ValidationException::withMessages(['rule' => 'Team-based rules are no longer supported. Edit the rule to assign a user or a user rotation first.']);
        }

        $this->rules->setActive($rule, ! $rule->is_active, $request->user());

        return back()->with('success', $rule->is_active ? "Rule \"{$rule->name}\" enabled." : "Rule \"{$rule->name}\" disabled.");
    }

    public function destroy(Request $request, LeadAssignmentRule $rule): RedirectResponse
    {
        $this->rules->delete($rule, $request->user());

        return back()->with('success', "Rule \"{$rule->name}\" deleted.");
    }

    public function reorder(Request $request): RedirectResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array', 'max:200'], 'ids.*' => ['integer', Rule::exists('lead_assignment_rules', 'id')->whereNull('deleted_at')]])['ids'];
        $this->rules->reorder($ids, $request->user());

        return back()->with('success', 'Rule priorities saved.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'condition_type' => ['required', Rule::in(array_map(fn (RuleCondition $c) => $c->value, RuleCondition::supported()))],
            'condition_value' => ['nullable', 'string', 'max:150'],
            'assignment_type' => ['required', Rule::in(array_map(fn (RuleAssignment $a) => $a->value, RuleAssignment::supported()))],
            'assigned_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')->where('is_active', true)],
            'user_pool' => ['nullable', 'array', 'max:100'],
            'user_pool.*' => ['integer', 'distinct', Rule::exists('users', 'id')->whereNull('deleted_at')->where('is_active', true)],
            'priority' => ['required', 'integer', 'between:1,65000'],
            'is_active' => ['boolean'],
        ]);

        $condition = RuleCondition::from($data['condition_type']);
        $assignment = RuleAssignment::from($data['assignment_type']);
        $value = trim((string) ($data['condition_value'] ?? ''));

        $errors = [];
        if ($condition !== RuleCondition::Any && $value === '') {
            $errors['condition_value'] = 'Provide a value for this condition.';
        }
        if ($condition === RuleCondition::Source && ! LeadSource::whereKey($value)->exists()) {
            $errors['condition_value'] = 'Select a valid source.';
        }
        if ($condition === RuleCondition::Campaign && ! Campaign::whereKey($value)->exists()) {
            $errors['condition_value'] = 'Select a valid campaign.';
        }
        if ($condition === RuleCondition::FacebookForm && ! FacebookForm::where('form_id', $value)->exists()) {
            $errors['condition_value'] = 'Select a synced Facebook form.';
        }
        if ($assignment === RuleAssignment::User && empty($data['assigned_user_id'])) {
            $errors['assigned_user_id'] = 'Select the user who receives these leads.';
        }
        if ($assignment === RuleAssignment::RoundRobin && count($data['user_pool'] ?? []) < 1) {
            $errors['user_pool'] = 'Select at least one user for the rotation.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'name' => $data['name'],
            'condition_type' => $condition,
            'condition_value' => $condition === RuleCondition::Any ? null : $value,
            'assignment_type' => $assignment,
            'assigned_user_id' => $assignment === RuleAssignment::User ? $data['assigned_user_id'] : null,
            'assigned_team_id' => null,
            'user_pool_json' => $assignment === RuleAssignment::RoundRobin ? array_values(array_map('intval', $data['user_pool'])) : null,
            'priority' => $data['priority'],
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }
}
