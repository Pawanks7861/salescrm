<?php

namespace App\Services\Reports\Definitions;

use App\Enums\AssignmentType;
use App\Services\Reports\Metric;
use App\Services\Reports\Metrics\AssignmentMetrics;
use App\Services\Reports\ReportLinks;
use App\Services\Reports\ReportLookups;
use App\Services\Reports\ReportQueries;

class AssignmentsReport extends ReportDefinition
{
    public const SLUG = 'assignments';

    public const TITLE = 'Assignments & reassignments';

    public const CATEGORY = 'Leads';

    public const DESCRIPTION = 'How leads were distributed and moved between people in the period.';

    public const FILTERS = ['date', 'user', 'source', 'campaign', 'priority', 'city', 'state', 'archived'];

    public function __construct(ReportLookups $lookups, private readonly AssignmentMetrics $assignments)
    {
        parent::__construct($lookups);
    }

    public function sections(ReportQueries $q, ReportLinks $links): array
    {
        $s = $this->assignments->summary($q);
        $t = $this->assignments->timeToAssignment($q);

        $sections = [
            $this->kpis('summary', 'Assignment activity', [
                Metric::kpi('total', 'Assignment changes', $s['total'], 'number', 'Rows in the assignment history in the period.'),
                Metric::kpi('first', 'First assignments', $s['first_assignments'], 'number', 'Lead given to a user when it had none.'),
                Metric::kpi('reassignments', 'Reassignments', $s['reassignments'], 'number', 'Lead moved from one user to another.'),
                Metric::kpi('unassignments', 'Moved to a queue / cleared', $s['unassignments'], 'number', 'Changes that left the lead without a user.'),
                Metric::kpi('median_assign', 'Median time to assignment', $t['median'], 'duration', 'Leads created in the period: creation → first assignment to a user.', higherIsBetter: false),
                Metric::kpi('unassigned_now', 'Unassigned open leads (now)', $s['unassigned_open_now'], 'number', 'Open leads with no owner right now.', snapshot: true, higherIsBetter: false, link: $links->leads(['assignee' => 'unassigned'])),
            ]),
        ];

        $types = $this->assignments->byType($q);
        $rows = array_map(fn ($type, $c) => [
            'name' => ucwords(str_replace('_', ' ', AssignmentType::tryFrom((string) $type)?->value ?? (string) $type)),
            'count' => $c,
            'share' => Metric::rate($c, $s['total']),
        ], array_keys($types), $types);
        $sections[] = $this->chart('types_chart', 'How leads were assigned', 'donut', array_column($rows, 'name'), [
            ['label' => 'Changes', 'data' => array_column($rows, 'count')],
        ]);
        $sections[] = $this->table('by_type', 'By assignment method', [
            $this->col('name', 'Method', 'text'), $this->col('count', 'Changes'), $this->col('share', 'Share', 'percent'),
        ], $rows, null, 'No assignment changes in the selected period.');

        $u = $this->assignments->byUser($q);
        $scopeUsers = $q->scope->users()->pluck('id')->map(fn ($id) => (string) $id)->flip();
        $ids = collect($u['received']->keys())->merge($u['removed']->keys())->merge($u['by']->keys())
            ->map(fn ($id) => (string) $id)->unique()->filter(fn ($id) => $scopeUsers->has($id));
        $userRows = $ids->map(fn ($id) => [
            'name' => $this->lookups->userName((int) $id),
            'received' => (int) ($u['received'][$id] ?? 0),
            'removed' => (int) ($u['removed'][$id] ?? 0),
            'assigned_by' => (int) ($u['by'][$id] ?? 0),
        ])->sortBy('name')->values()->all();
        $sections[] = $this->table('by_user', 'By person', [
            $this->col('name', 'Person', 'text'), $this->col('received', 'Leads received'), $this->col('removed', 'Leads moved away'), $this->col('assigned_by', 'Assignments made'),
        ], $userRows, null, 'No assignment changes in the selected period.', 'Counts only changes on leads you can currently see.');

        return $sections;
    }
}
