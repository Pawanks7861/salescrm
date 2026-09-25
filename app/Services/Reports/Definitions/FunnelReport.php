<?php

namespace App\Services\Reports\Definitions;

use App\Services\Reports\Metric;
use App\Services\Reports\Metrics\ConversionMetrics;
use App\Services\Reports\ReportLinks;
use App\Services\Reports\ReportLookups;
use App\Services\Reports\ReportQueries;

class FunnelReport extends ReportDefinition
{
    public const SLUG = 'funnel';

    public const TITLE = 'Funnel & conversion';

    public const CATEGORY = 'Sales';

    public const DESCRIPTION = 'How far leads created in the period progressed, time spent per stage and time to win.';

    public const FILTERS = ['date', 'user', 'source', 'campaign', 'priority', 'city', 'state', 'archived'];

    public function __construct(ReportLookups $lookups, private readonly ConversionMetrics $conversion)
    {
        parent::__construct($lookups);
    }

    public function sections(ReportQueries $q, ReportLinks $links): array
    {
        $funnel = $this->conversion->funnel($q);
        $start = $funnel[0]['reached'] ?? 0;
        $qualified = $this->conversion->qualified($funnel);
        $won = collect($funnel)->firstWhere('is_won', true)['reached'] ?? 0;
        $qualifiedStatus = $this->lookups->qualifiedStatus();

        $sections = [
            $this->kpis('summary', 'Leads created in the period', [
                Metric::kpi('cohort', 'Leads created', $start, 'number', 'Acquisition cohort for the period.', link: $links->leads([], true)),
                Metric::kpi('qualified', 'Reached qualified', $qualified, 'number', 'Reached "'.($qualifiedStatus?->name ?? 'Interested').'" or a later non-lost stage (setting: report.qualified_status).'),
                Metric::kpi('qualified_rate', 'Qualification rate', Metric::rate($qualified, $start), 'percent', 'Reached qualified ÷ leads created.'),
                Metric::kpi('won', 'Reached won', $won, 'number', 'Cohort leads that reached a won status at any point (includes leads later reopened).'),
                Metric::kpi('won_rate', 'Lead → won', Metric::rate($won, $start), 'percent', 'Reached won ÷ leads created.'),
            ]),
            $this->chart('funnel_chart', 'Funnel (reached or passed each stage)', 'bar', array_column($funnel, 'name'), [
                ['label' => 'Leads', 'data' => array_column($funnel, 'reached'),
                    'colors' => array_map(fn ($s) => $this->lookups->statuses()->get($s['status_id'])?->color, $funnel)],
            ]),
            $this->table('funnel', 'Stage conversion', [
                $this->col('name', 'Stage', 'text'), $this->col('reached', 'Reached'),
                $this->col('rate_from_start', '% of created', 'percent'), $this->col('rate_from_previous', '% of previous stage', 'percent'),
            ], array_map(fn ($s) => [...$s, '_links' => []], $funnel), null, 'No leads were created in the selected period.',
                'Built only from recorded status history: a lead counts for a stage when its history (or its current, non-lost status) reached that stage or a later one. Leads that skip stages count for the skipped stages too.'),
        ];

        $durations = $this->conversion->stageDurations($q);
        $sections[] = $this->table('stage_duration', 'Time spent in each stage', [
            $this->col('name', 'Stage', 'text'), $this->col('exits', 'Stage exits'), $this->col('avg', 'Average', 'duration'),
            $this->col('p50', 'Median', 'duration'), $this->col('p75', '75th percentile', 'duration'), $this->col('p90', '90th percentile', 'duration'),
        ], $durations, null, 'No stage changes in the selected period.',
            'For leads that LEFT a stage during the period: time from entering the stage to the next status change. Leads still in a stage are shown in the Ageing report.');

        $win = $this->conversion->timeTo($q, 'won');
        $loss = $this->conversion->timeTo($q, 'lost');
        $sections[] = $this->table('timing', 'Time to outcome', [
            $this->col('name', 'Outcome', 'text'), $this->col('count', 'Leads'), $this->col('avg', 'Average', 'duration'),
            $this->col('p50', 'Median', 'duration'), $this->col('p75', '75th percentile', 'duration'), $this->col('p90', '90th percentile', 'duration'),
        ], array_values(array_filter([
            $win['count'] ? ['name' => 'Created → won', ...$win] : null,
            $loss['count'] ? ['name' => 'Created → lost', ...$loss] : null,
        ])), null, 'No leads were won or lost in the selected period.', 'Leads won / lost during the period, measured from lead creation to the first won / lost change in the period.');

        return $sections;
    }
}
