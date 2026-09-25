<?php

namespace App\Services\Reports\Definitions;

use App\Enums\LeadPriority;
use App\Services\Reports\Metric;
use App\Services\Reports\Metrics\LeadMetrics;
use App\Services\Reports\ReportLinks;
use App\Services\Reports\ReportLookups;
use App\Services\Reports\ReportQueries;
use App\Services\Reports\ReportScope;

class LeadsReport extends ReportDefinition
{
    public const SLUG = 'leads';

    public const TITLE = 'Lead analytics';

    public const CATEGORY = 'Leads';

    public const DESCRIPTION = 'Leads created in the period by status, source, campaign, priority, location and owner.';

    public function __construct(ReportLookups $lookups, private readonly LeadMetrics $leads)
    {
        parent::__construct($lookups);
    }

    public function sections(ReportQueries $q, ReportLinks $links): array
    {
        $p = $this->prev($q);
        $s = $this->leads->cohortSummary($q);
        $ps = $p ? $this->leads->cohortSummary($p) : null;

        $sections = [
            $this->kpis('summary', 'Leads created in the period', [
                Metric::kpi('created', 'Leads created', $s['leads'], 'number', 'leads.created_at in the period.', $ps['leads'] ?? null, link: $links->leads([], true)),
                Metric::kpi('open', 'Still open', $s['open_now'], 'number', 'Of these, not won or lost today.', $ps['open_now'] ?? null),
                Metric::kpi('won', 'Won now', $s['won_now'], 'number', 'Of these, currently in a won status.', $ps['won_now'] ?? null),
                Metric::kpi('conversion', 'Cohort conversion', $s['cohort_win_rate'], 'percent', 'Won now ÷ leads created.', $ps['cohort_win_rate'] ?? null),
                Metric::kpi('duplicates', 'Flagged duplicates', $s['duplicates'], 'number', 'Created leads flagged as possible duplicates.', $ps['duplicates'] ?? null, link: $links->leads(['duplicates' => 1], true), higherIsBetter: false),
                Metric::kpi('unassigned', 'Unassigned', $s['unassigned'], 'number', 'Created leads with no owner today.', $ps['unassigned'] ?? null, link: $links->leads(['assignee' => 'unassigned'], true), higherIsBetter: false),
                $q->filters->includeArchived ? Metric::kpi('archived', 'Archived since', $s['archived'], 'number', 'Created leads that have since been archived (included in these numbers).', $ps['archived'] ?? null) : null,
            ], $p !== null),
        ];

        $trend = $this->leads->createdTrend($q);
        $sections[] = $this->chart('trend', 'Leads created', 'bar', $this->bucketLabels(array_keys($trend), $q->filters->granularity()), [
            ['label' => 'Leads created', 'data' => $trend],
        ]);

        $dimensions = [
            ['by_status', 'By current status', 'leads.status_id', fn ($k) => $this->lookups->statusName((int) $k), 'status', null],
            ['by_source', 'By source', 'leads.source_id', fn ($k) => $this->lookups->sourceName((int) $k), 'source', null],
            ['by_campaign', 'By campaign', 'leads.campaign_id', fn ($k) => $this->lookups->campaignName($k ? (int) $k : null), 'campaign', null],
            ['by_priority', 'By priority', 'leads.priority', fn ($k) => LeadPriority::tryFrom((string) $k)?->label() ?? (string) $k, 'priority', null],
        ];
        if ($q->scope->tier !== ReportScope::OWN) {
            $dimensions[] = ['by_owner', 'By owner', 'leads.assigned_to', fn ($k) => $this->lookups->userName($k ? (int) $k : null), 'assignee', null];
        }
        $dimensions[] = ['by_city', 'By city (top 25)', 'leads.city', fn ($k) => $k ?: 'Not set', 'city', 25];
        $dimensions[] = ['by_state', 'By state (top 25)', 'leads.state', fn ($k) => $k ?: 'Not set', 'state', 25];

        foreach ($dimensions as [$key, $title, $column, $label, $param, $limit]) {
            $rows = $this->leads->breakdown($q, $column, $limit)->map(fn ($r) => [
                'name' => $label($r->k),
                'leads' => $r->leads,
                'open' => $r->open,
                'won' => $r->won,
                'lost' => $r->lost,
                'conversion' => Metric::rate($r->won, $r->leads),
                'won_value' => $r->won_value,
                '_links' => ['leads' => $r->k === null && $param !== 'assignee' ? null : $links->leads([$param => $r->k ?? 'unassigned'], true)],
            ])->all();

            $sections[] = $this->table($key, $title, [
                $this->col('name', ucfirst(str_replace(['By ', ' (top 25)'], '', $title)), 'text'),
                $this->col('leads', 'Leads'), $this->col('open', 'Open'), $this->col('won', 'Won'), $this->col('lost', 'Lost'),
                $this->col('conversion', 'Conversion', 'percent'), $this->col('won_value', 'Won value', 'currency'),
            ], $rows, $limit ? null : $this->totals($rows, ['leads', 'open', 'won', 'lost', 'won_value'], extra: [
                'conversion' => Metric::rate(array_sum(array_column($rows, 'won')), array_sum(array_column($rows, 'leads'))),
            ]));
        }

        return $sections;
    }
}
