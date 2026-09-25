<?php

namespace App\Services\Reports\Definitions;

use App\Services\Reports\Metric;
use App\Services\Reports\Metrics\PipelineMetrics;
use App\Services\Reports\ReportLinks;
use App\Services\Reports\ReportLookups;
use App\Services\Reports\ReportQueries;
use App\Services\Reports\ReportScope;

class PipelineReport extends ReportDefinition
{
    public const SLUG = 'pipeline';

    public const TITLE = 'Pipeline';

    public const CATEGORY = 'Sales';

    public const DESCRIPTION = 'Open leads by stage and owner, as of now.';

    public const FILTERS = ['user', 'source', 'campaign', 'priority', 'city', 'state'];

    public function __construct(ReportLookups $lookups, private readonly PipelineMetrics $pipeline)
    {
        parent::__construct($lookups);
    }

    public function sections(ReportQueries $q, ReportLinks $links): array
    {
        $t = $this->pipeline->totals($q);
        $leads = (int) ($t->leads ?? 0);
        $withValue = $leads - (int) ($t->no_value ?? 0);

        $sections = [
            $this->kpis('summary', 'Open pipeline (now)', [
                Metric::kpi('open', 'Open leads', $leads, 'number', 'Leads not won, not lost and not archived.', snapshot: true, link: $links->leads()),
                Metric::kpi('value', 'Estimated value', Metric::money($t->value ?? 0), 'currency', 'Sum of estimated_value of open leads.', snapshot: true),
                Metric::kpi('weighted', 'Weighted value', Metric::money($t->weighted ?? 0), 'currency', 'Estimated value × the probability configured on each status (admin setting, not a prediction).', snapshot: true),
                Metric::kpi('avg', 'Average deal value', $withValue ? Metric::money(($t->value ?? 0) / $withValue) : null, 'currency', 'Average over open leads that have an estimated value.', snapshot: true),
                Metric::kpi('no_value', 'Leads without value', (int) ($t->no_value ?? 0), 'number', 'Open leads with no estimated value; not included in value totals.', snapshot: true, higherIsBetter: false),
            ], note: 'The pipeline is a current snapshot and ignores the date range. For historical progression see the Funnel report.'),
        ];

        $statuses = $this->pipeline->byStatus($q);
        $rows = $statuses->map(fn ($r) => [
            'name' => $this->lookups->statusName((int) $r->k),
            'leads' => (int) $r->leads,
            'value' => Metric::money($r->value),
            'no_value' => (int) $r->no_value,
            'probability' => (float) ($this->lookups->statuses()->get((int) $r->k)?->probability ?? 0),
            'weighted' => Metric::money($r->weighted),
            '_links' => ['leads' => $links->leads(['status' => $r->k])],
        ])->all();

        $sections[] = $this->chart('by_status_chart', 'Estimated value by status', 'bar', array_column($rows, 'name'), [
            ['label' => 'Estimated value', 'data' => array_column($rows, 'value'),
                'colors' => $statuses->map(fn ($r) => $this->lookups->statuses()->get((int) $r->k)?->color)->all()],
        ], 'currency');
        $sections[] = $this->table('by_status', 'By status', [
            $this->col('name', 'Status', 'text'), $this->col('leads', 'Open leads'), $this->col('value', 'Estimated value', 'currency'),
            $this->col('no_value', 'Without value'), $this->col('probability', 'Status probability', 'percent'), $this->col('weighted', 'Weighted value', 'currency'),
        ], $rows, $this->totals($rows, ['leads', 'value', 'no_value', 'weighted']), 'No open leads.');

        if ($q->scope->tier !== ReportScope::OWN) {
            $owners = $this->pipeline->by($q, 'leads.assigned_to')->map(fn ($r) => [
                'name' => $this->lookups->userName($r->k ? (int) $r->k : null),
                'leads' => (int) $r->leads,
                'value' => Metric::money($r->value),
                'no_value' => (int) $r->no_value,
                'weighted' => Metric::money($r->weighted),
                '_links' => ['leads' => $links->leads(['assignee' => $r->k ?: 'unassigned'])],
            ])->sortBy('name')->values()->all();
            $sections[] = $this->table('by_owner', 'By owner', [
                $this->col('name', 'Owner', 'text'), $this->col('leads', 'Open leads'), $this->col('value', 'Estimated value', 'currency'),
                $this->col('no_value', 'Without value'), $this->col('weighted', 'Weighted value', 'currency'),
            ], $owners, $this->totals($owners, ['leads', 'value', 'no_value', 'weighted']), 'No open leads.');
        }

        return $sections;
    }
}
