<?php

namespace App\Services\Reports\Definitions;

use App\Models\Lead;
use App\Services\Reports\Metric;
use App\Services\Reports\Metrics\PipelineMetrics;
use App\Services\Reports\ReportLinks;
use App\Services\Reports\ReportLookups;
use App\Services\Reports\ReportQueries;
use App\Services\Reports\ReportScope;
use App\Support\CrmTime;

class AgeingReport extends ReportDefinition
{
    public const SLUG = 'ageing';

    public const TITLE = 'Lead ageing & neglected leads';

    public const CATEGORY = 'Leads';

    public const DESCRIPTION = 'How old open leads are, how long they have sat in their stage, and which need attention.';

    public const FILTERS = ['user', 'source', 'campaign', 'status', 'priority', 'city', 'state'];

    private const LIST_LIMIT = 50;

    public function __construct(ReportLookups $lookups, private readonly PipelineMetrics $pipeline)
    {
        parent::__construct($lookups);
    }

    public function sections(ReportQueries $q, ReportLinks $links): array
    {
        $buckets = PipelineMetrics::AGE_BUCKETS;
        $byStatus = $this->pipeline->ageing($q, 'leads.status_id')->groupBy('g');
        $total = 0;
        $bucketTotals = array_fill_keys(array_column($buckets, 0), 0);
        $rows = [];
        foreach ($this->lookups->statuses() as $status) {
            if (! isset($byStatus[$status->id])) {
                continue;
            }
            $row = ['name' => $status->name, '_color' => $status->color];
            foreach ($buckets as [$key]) {
                $row[$key] = (int) ($byStatus[$status->id]->firstWhere('b', $key)->c ?? 0);
                $bucketTotals[$key] += $row[$key];
            }
            $row['total'] = array_sum(array_intersect_key($row, $bucketTotals));
            $row['_links'] = ['total' => $links->leads(['status' => $status->id])];
            $total += $row['total'];
            $rows[] = $row;
        }
        $bucketColumns = array_map(fn ($b) => $this->col($b[0], $b[1]), $buckets);

        $neglect = $this->pipeline->neglectedCounts($q);
        $n = $neglect['total'];

        $sections = [
            $this->kpis('summary', 'Open leads by age (now)', [
                Metric::kpi('open', 'Open leads', $total, 'number', 'Not won, not lost, not archived.', snapshot: true, link: $links->leads()),
                Metric::kpi('over_15', 'Older than 15 days', $bucketTotals['16_30'] + $bucketTotals['31_plus'], 'number', 'Open leads created more than 15 days ago.', snapshot: true, higherIsBetter: false),
                Metric::kpi('over_30', 'Older than 30 days', $bucketTotals['31_plus'], 'number', 'Open leads created more than 30 days ago.', snapshot: true, higherIsBetter: false),
                Metric::kpi('neglected', 'Need attention', (int) ($n->total ?? 0), 'number', 'Open leads matching at least one neglect rule below.', snapshot: true, higherIsBetter: false),
            ], note: 'Ageing is a current snapshot and ignores the date range. Age = whole days since the lead was created.'),
            $this->chart('ageing_chart', 'Open leads by age and status', 'stacked', array_column($buckets, 1),
                array_map(fn ($r) => ['label' => $r['name'], 'data' => array_map(fn ($b) => $r[$b[0]], $buckets), 'color' => $r['_color']], $rows)),
            $this->table('by_status', 'Age by status', [$this->col('name', 'Status', 'text'), ...$bucketColumns, $this->col('total', 'Total')],
                $rows, $rows ? ['name' => 'Total', ...$bucketTotals, 'total' => $total] : null, 'No open leads.'),
        ];

        if ($q->scope->tier !== ReportScope::OWN) {
            $owners = $this->pipeline->ageing($q, 'leads.assigned_to')->groupBy('g')->map(function ($items, $k) use ($buckets, $links) {
                $row = ['name' => $this->lookups->userName($k ? (int) $k : null)];
                foreach ($buckets as [$key]) {
                    $row[$key] = (int) ($items->firstWhere('b', $key)->c ?? 0);
                }
                $row['total'] = (int) $items->sum('c');
                $row['_links'] = ['total' => $links->leads(['assignee' => $k ?: 'unassigned'])];

                return $row;
            })->sortBy('name')->values()->all();
            $sections[] = $this->table('by_owner', 'Age by owner', [$this->col('name', 'Owner', 'text'), ...$bucketColumns, $this->col('total', 'Total')],
                $owners, $this->totals($owners, [...array_column($buckets, 0), 'total']), 'No open leads.');
        }

        $inStatus = $this->pipeline->timeInStatus($q)->keyBy('k');
        $statusRows = [];
        foreach ($this->lookups->statuses() as $status) {
            if ($r = $inStatus[$status->id] ?? null) {
                $statusRows[] = ['name' => $status->name, 'leads' => (int) $r->leads, 'avg_days' => round((float) $r->avg_days, 1), 'max_days' => round((float) $r->max_days, 1)];
            }
        }
        $sections[] = $this->table('time_in_status', 'Time in current status', [
            $this->col('name', 'Status', 'text'), $this->col('leads', 'Open leads'), $this->col('avg_days', 'Average days', 'days'), $this->col('max_days', 'Longest (days)', 'days'),
        ], $statusRows, null, 'No open leads.', 'Days since the lead\'s last status change.');

        $sections[] = $this->kpis('neglect', 'Neglected lead rules', [
            Metric::kpi('untouched', PipelineMetrics::NEGLECT_REASONS['untouched'], (int) ($n->untouched ?? 0), 'number', 'Created more than '.$this->pipeline->untouchedHours().' hours ago with no call, completed follow-up or meeting (setting report.untouched_new_lead_hours).', snapshot: true, higherIsBetter: false),
            Metric::kpi('overdue', PipelineMetrics::NEGLECT_REASONS['overdue'], (int) ($n->overdue ?? 0), 'number', 'At least one pending follow-up past its time.', snapshot: true, higherIsBetter: false),
            Metric::kpi('no_next_action', PipelineMetrics::NEGLECT_REASONS['no_next_action'], (int) ($n->no_next_action ?? 0), 'number', 'No pending follow-up and no open meeting ahead.', snapshot: true, higherIsBetter: false),
            Metric::kpi('inactive', PipelineMetrics::NEGLECT_REASONS['inactive'], (int) ($n->inactive ?? 0), 'number', 'Not contacted in the last '.$this->pipeline->inactiveDays().' days (setting report.inactive_days).', snapshot: true, higherIsBetter: false),
        ], note: 'Derived when the page loads; nothing is stored or changed on the leads.');

        if ($q->scope->tier !== ReportScope::OWN) {
            $ownerRows = collect($neglect['by_owner'])->map(fn ($r) => [
                'name' => $this->lookups->userName($r->k ? (int) $r->k : null),
                'total' => (int) $r->total, 'untouched' => (int) $r->untouched, 'overdue' => (int) $r->overdue,
                'no_next_action' => (int) $r->no_next_action, 'inactive' => (int) $r->inactive,
            ])->sortBy('name')->values()->all();
            $sections[] = $this->table('neglect_by_owner', 'Neglected leads by owner', $this->neglectColumns('Owner'), $ownerRows,
                $this->totals($ownerRows, ['total', 'untouched', 'overdue', 'no_next_action', 'inactive']), 'No neglected leads. ');
        }

        $count = (int) ($n->total ?? 0);
        $sections[] = $this->table('neglected', 'Leads needing attention'.($count > self::LIST_LIMIT ? ' (oldest '.self::LIST_LIMIT.' of '.$count.')' : ''),
            $this->listColumns(), $this->listRows($q, $links, self::LIST_LIMIT), null, 'No neglected leads.', 'Export includes every matching lead.');

        return $sections;
    }

    public function exportTable(ReportQueries $q, ReportLinks $links, string $section): ?array
    {
        if ($section !== 'neglected') {
            return parent::exportTable($q, $links, $section);
        }

        return ['columns' => $this->listColumns(), 'rows' => $this->listRows($q, $links, null, lazy: true), 'count' => $this->exportCount($q, $links, $section)];
    }

    public function exportCount(ReportQueries $q, ReportLinks $links, string $section): ?int
    {
        return $section === 'neglected'
            ? (int) ($this->pipeline->neglectedCounts($q)['total']->total ?? 0)
            : parent::exportCount($q, $links, $section);
    }

    private function neglectColumns(string $label): array
    {
        return [
            $this->col('name', $label, 'text'), $this->col('total', 'Leads'), $this->col('untouched', 'Not attempted'),
            $this->col('overdue', 'Overdue follow-up'), $this->col('no_next_action', 'No next action'), $this->col('inactive', 'Inactive'),
        ];
    }

    private function listColumns(): array
    {
        return [
            $this->col('lead_number', 'Lead', 'text'), $this->col('name', 'Name', 'text'), $this->col('status', 'Status', 'text'),
            $this->col('owner', 'Owner', 'text'), $this->col('age_days', 'Age (days)'), $this->col('last_contacted', 'Last contacted', 'text'),
            $this->col('reasons', 'Why', 'text'),
        ];
    }

    private function listRows(ReportQueries $q, ReportLinks $links, ?int $limit, bool $lazy = false): iterable
    {
        $query = $this->pipeline->neglected($q)->orderBy('leads.created_at')->orderBy('leads.id')->when($limit, fn ($b) => $b->limit($limit));
        $map = function (Lead $lead) use ($links) {
            $reasons = array_values(array_filter(array_map(fn ($k) => $lead->{"n_{$k}"} ? PipelineMetrics::NEGLECT_REASONS[$k] : null, array_keys(PipelineMetrics::NEGLECT_REASONS))));

            return [
                'lead_number' => $lead->id.' · '.$lead->lead_number,
                'name' => $lead->full_name,
                'status' => $this->lookups->statusName($lead->status_id),
                'owner' => $this->lookups->userName($lead->assigned_to),
                'age_days' => (int) floor($lead->created_at->diffInDays(now())),
                'last_contacted' => $lead->last_contacted_at ? CrmTime::format($lead->last_contacted_at) : 'Never',
                'reasons' => implode('; ', $reasons),
                '_links' => ['lead_number' => $links->lead($lead->id)],
            ];
        };

        return $lazy ? $query->reorder()->lazyById(500, 'leads.id', 'id')->map($map) : $query->get()->map($map)->all();
    }
}
