<?php

namespace App\Services\Reports\Definitions;

use App\Enums\FollowupOutcome;
use App\Models\Followup;
use App\Services\Reports\Metric;
use App\Services\Reports\Metrics\FollowupMetrics;
use App\Services\Reports\Metrics\LeadMetrics;
use App\Services\Reports\ReportLinks;
use App\Services\Reports\ReportLookups;
use App\Services\Reports\ReportQueries;
use App\Services\Reports\ReportScope;

class FollowupsReport extends ReportDefinition
{
    public const SLUG = 'follow-ups';

    public const TITLE = 'Follow-up analytics';

    public const CATEGORY = 'Activity';

    public const DESCRIPTION = 'Follow-ups due and completed, on-time rate, overdue work and outcomes.';

    public const FILTERS = ['date', 'user', 'source', 'campaign', 'status', 'priority', 'city', 'state', 'archived', 'compare'];

    public function __construct(ReportLookups $lookups, private readonly FollowupMetrics $followups, private readonly LeadMetrics $leads)
    {
        parent::__construct($lookups);
    }

    public function availableTo(ReportScope $scope): bool
    {
        return $scope->user->can('viewAny', Followup::class);
    }

    public function sections(ReportQueries $q, ReportLinks $links): array
    {
        $p = $this->prev($q);
        $s = $this->followups->summary($q);
        $ps = $p ? $this->followups->summary($p) : null;
        $grace = $this->followups->graceMinutes();

        $sections = [
            $this->kpis('summary', 'Follow-ups', [
                Metric::kpi('due', 'Due in period', $s['due'], 'number', 'Scheduled between the period start and now (or period end), excluding follow-ups replaced by a reschedule.', $ps['due'] ?? null, link: $links->followups()),
                Metric::kpi('completed', 'Completed (of due)', $s['completed'], 'number', null, $ps['completed'] ?? null),
                Metric::kpi('completion_rate', 'Completion rate', $s['completion_rate'], 'percent', 'Completed ÷ due.', $ps['completion_rate'] ?? null),
                Metric::kpi('on_time_rate', 'On-time rate', $s['on_time_rate'], 'percent', "Completed within {$grace} minutes of the scheduled time ÷ completed (setting followup.overdue_alert_after_minutes).", $ps['on_time_rate'] ?? null),
                Metric::kpi('completed_in_period', 'Completed in period', $s['completed_in_period'], 'number', 'Any follow-up marked completed in the period, whenever it was due.', $ps['completed_in_period'] ?? null),
                Metric::kpi('cancelled', 'Cancelled', $s['cancelled'], 'number', null, $ps['cancelled'] ?? null, higherIsBetter: false),
                Metric::kpi('rescheduled', 'Rescheduled', $s['rescheduled'], 'number', 'Follow-ups that were moved to a new time.', $ps['rescheduled'] ?? null),
                Metric::kpi('overdue_now', 'Overdue (now)', $s['overdue_now'], 'number', 'Pending and past the scheduled time right now.', snapshot: true, higherIsBetter: false, link: $links->followups(['tab' => 'overdue'], false)),
            ], $p !== null),
        ];

        $due = $this->leads->trend($this->followups->due($q), 'followups.scheduled_at', $q);
        $done = $this->leads->trend($this->followups->due($q), 'followups.scheduled_at', $q, "SUM(CASE WHEN followups.status = 'completed' THEN 1 ELSE 0 END)");
        $sections[] = $this->chart('trend', 'Due vs completed (by scheduled date)', 'line', $this->bucketLabels(array_keys($due), $q->filters->granularity()), [
            ['label' => 'Due', 'data' => $due], ['label' => 'Completed', 'data' => $done],
        ]);

        $groups = [['by_assignee', 'By assignee', 'followups.assigned_to', fn ($k) => $this->lookups->userName($k !== '' ? (int) $k : null), 'assigned_to']];
        foreach ($groups as [$key, $title, $col, $label, $param]) {
            $rows = $this->followups->by($q, $col)->map(fn ($r, $k) => [
                'name' => $label((string) $k), ...$r,
                '_links' => $k !== '' ? array_filter([
                    'due' => $links->followups([$param => $k]),
                    'overdue_now' => $links->followups(['tab' => 'overdue', $param => $k], false),
                ]) : [],
            ])->sortBy('name')->values()->all();
            $sections[] = $this->table($key, $title, [
                $this->col('name', 'Assignee', 'text'), $this->col('due', 'Due'), $this->col('completed', 'Completed'),
                $this->col('completion_rate', 'Completion rate', 'percent'), $this->col('on_time_rate', 'On-time rate', 'percent'),
                $this->col('cancelled', 'Cancelled'), $this->col('completed_in_period', 'Completed in period'), $this->col('overdue_now', 'Overdue (now)'),
            ], $rows, $this->totals($rows, ['due', 'completed', 'cancelled', 'completed_in_period', 'overdue_now'], extra: [
                'completion_rate' => $s['completion_rate'], 'on_time_rate' => $s['on_time_rate'],
            ]), 'No follow-ups in the selected period.');
        }

        $outcomes = $this->followups->outcomes($q);
        $total = array_sum($outcomes);
        $outcomeRows = array_map(fn ($o, $c) => [
            'name' => FollowupOutcome::tryFrom((string) $o)?->label() ?? 'Not recorded',
            'count' => $c,
            'share' => Metric::rate($c, $total),
            'contact' => FollowupOutcome::tryFrom((string) $o)?->countsAsContact() ? 'Yes' : 'No',
        ], array_keys($outcomes), $outcomes);
        $sections[] = $this->chart('outcomes_chart', 'Outcomes of completed follow-ups', 'donut', array_column($outcomeRows, 'name'), [
            ['label' => 'Follow-ups', 'data' => array_column($outcomeRows, 'count')],
        ]);
        $sections[] = $this->table('outcomes', 'Outcomes (completed in period)', [
            $this->col('name', 'Outcome', 'text'), $this->col('count', 'Follow-ups'), $this->col('share', 'Share', 'percent'), $this->col('contact', 'Counts as contact', 'text'),
        ], $outcomeRows, null, 'No follow-ups were completed in the selected period.');

        $types = $this->followups->byType($q)->map(fn ($r) => [
            'name' => $this->lookups->followupTypeName((int) $r->k), 'due' => (int) $r->due, 'completed' => (int) $r->completed,
            'completion_rate' => Metric::rate($r->completed, $r->due),
            '_links' => ['due' => $links->followups(['type' => $r->k])],
        ])->sortByDesc('due')->values()->all();
        $sections[] = $this->table('by_type', 'By type', [
            $this->col('name', 'Type', 'text'), $this->col('due', 'Due'), $this->col('completed', 'Completed'), $this->col('completion_rate', 'Completion rate', 'percent'),
        ], $types, null, 'No follow-ups in the selected period.');

        return $sections;
    }
}
