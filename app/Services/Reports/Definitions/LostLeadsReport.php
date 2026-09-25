<?php

namespace App\Services\Reports\Definitions;

use App\Services\Reports\Metric;
use App\Services\Reports\Metrics\ConversionMetrics;
use App\Services\Reports\Metrics\LeadMetrics;
use App\Services\Reports\ReportLinks;
use App\Services\Reports\ReportLookups;
use App\Services\Reports\ReportQueries;
use App\Services\Reports\ReportScope;

class LostLeadsReport extends ReportDefinition
{
    public const SLUG = 'lost-leads';

    public const TITLE = 'Lost leads';

    public const CATEGORY = 'Sales';

    public const DESCRIPTION = 'Leads lost in the period by reason, stage, source and owner.';

    public const FILTERS = ['date', 'user', 'source', 'campaign', 'priority', 'city', 'state', 'archived', 'compare'];

    public function __construct(
        ReportLookups $lookups,
        private readonly LeadMetrics $leads,
        private readonly ConversionMetrics $conversion,
    ) {
        parent::__construct($lookups);
    }

    public function sections(ReportQueries $q, ReportLinks $links): array
    {
        $p = $this->prev($q);
        $lost = $this->leads->periodOutcome($q, 'lost');
        $won = $this->leads->periodOutcome($q, 'won');
        $pLost = $p ? $this->leads->periodOutcome($p, 'lost') : null;
        $timing = $this->conversion->timeTo($q, 'lost');
        $lostStatus = $this->lookups->lostIds()[0] ?? null;

        $sections = [
            $this->kpis('summary', 'Leads lost in the period', [
                Metric::kpi('lost', 'Leads lost', $lost['count'], 'number', 'Leads moved to a lost status in the period.', $pLost['count'] ?? null, higherIsBetter: false, link: $links->leads(['status' => $lostStatus])),
                Metric::kpi('lost_value', 'Lost value', $lost['value'], 'currency', 'Estimated value of those leads.', $pLost['value'] ?? null, higherIsBetter: false),
                Metric::kpi('loss_rate', 'Loss rate', Metric::rate($lost['count'], $lost['count'] + $won['count']), 'percent', 'Lost ÷ (won + lost) in the period.', $p ? Metric::rate($pLost['count'], $pLost['count'] + $this->leads->periodOutcome($p, 'won')['count']) : null, higherIsBetter: false),
                Metric::kpi('time_to_loss', 'Median time to loss', $timing['p50'], 'duration', 'From lead creation to being lost.'),
            ], $p !== null),
        ];

        $ids = $this->leads->transitionedIds($q, 'lost');
        $reasonRows = $q->leads()->whereIn('leads.id', $ids)->toBase()
            ->selectRaw('leads.lost_reason_id AS k, MAX(CASE WHEN leads.status_id IN ('.implode(',', $this->lookups->lostIds()).') THEN 1 ELSE 0 END) AS still_lost, COUNT(*) AS c, SUM(COALESCE(leads.estimated_value, 0)) AS v')
            ->groupBy('leads.lost_reason_id')->orderByDesc('c')->get()
            ->map(fn ($r) => [
                'name' => $r->k ? $this->lookups->lostReasonName((int) $r->k) : ((int) $r->still_lost ? 'No reason recorded' : 'Reopened since'),
                'leads' => (int) $r->c,
                'share' => Metric::rate($r->c, $lost['count']),
                'value' => Metric::money($r->v),
            ])->all();

        $sections[] = $this->chart('reasons_chart', 'Loss reasons', 'donut', array_column($reasonRows, 'name'), [
            ['label' => 'Leads', 'data' => array_column($reasonRows, 'leads')],
        ]);
        $sections[] = $this->table('reasons', 'By loss reason', [
            $this->col('name', 'Reason', 'text'), $this->col('leads', 'Leads'), $this->col('share', 'Share', 'percent'), $this->col('value', 'Estimated value', 'currency'),
        ], $reasonRows, $this->totals($reasonRows, ['leads', 'value']), 'No leads were lost in the selected period.',
            'Reason = the lost reason currently on the lead. Leads reopened after being lost appear as "Reopened since".');

        $stageRows = $q->statusChanges()
            ->whereIn('lead_status_changes.to_status_id', $this->lookups->lostIds())
            ->whereBetween('lead_status_changes.changed_at', [$q->filters->from, $q->filters->to])
            ->toBase()->selectRaw('lead_status_changes.from_status_id AS k, COUNT(DISTINCT lead_status_changes.lead_id) AS c')
            ->groupBy('lead_status_changes.from_status_id')->orderByDesc('c')->get()
            ->map(fn ($r) => ['name' => $this->lookups->statusName($r->k ? (int) $r->k : null), 'leads' => (int) $r->c])->all();
        $sections[] = $this->table('stages', 'Stage the lead was lost from', [
            $this->col('name', 'Previous stage', 'text'), $this->col('leads', 'Leads'),
        ], $stageRows, null, 'No leads were lost in the selected period.');

        $dims = [['sources', 'By source', 'leads.source_id', fn ($k) => $this->lookups->sourceName((int) $k)]];
        if ($q->scope->tier !== ReportScope::OWN) {
            $dims[] = ['owners', 'By owner', 'leads.assigned_to', fn ($k) => $this->lookups->userName($k ? (int) $k : null)];
        }
        foreach ($dims as [$key, $title, $col, $label]) {
            $rows = $this->leads->periodOutcomeBy($q, 'lost', $col)->map(fn ($r) => [
                'name' => $label($r->k), 'leads' => (int) $r->c, 'value' => Metric::money($r->v),
            ])->sortByDesc('leads')->values()->all();
            $sections[] = $this->table($key, $title, [
                $this->col('name', $key === 'owners' ? 'Owner' : 'Source', 'text'), $this->col('leads', 'Leads lost'), $this->col('value', 'Estimated value', 'currency'),
            ], $rows, $this->totals($rows, ['leads', 'value']), 'No leads were lost in the selected period.');
        }

        return $sections;
    }
}
