<?php

namespace App\Services\Reports\Definitions;

use App\Services\Reports\Metric;
use App\Services\Reports\Metrics\FollowupMetrics;
use App\Services\Reports\Metrics\LeadMetrics;
use App\Services\Reports\Metrics\MeetingMetrics;
use App\Services\Reports\Metrics\PipelineMetrics;
use App\Services\Reports\Metrics\ResponseMetrics;
use App\Services\Reports\ReportLinks;
use App\Services\Reports\ReportLookups;
use App\Services\Reports\ReportQueries;

class OverviewReport extends ReportDefinition
{
    public const SLUG = 'overview';

    public const TITLE = 'Sales overview';

    public const CATEGORY = 'Sales';

    public const DESCRIPTION = 'Headline numbers for the period with comparison to the previous period.';

    public function __construct(
        ReportLookups $lookups,
        private readonly LeadMetrics $leads,
        private readonly PipelineMetrics $pipeline,
        private readonly ResponseMetrics $response,
        private readonly FollowupMetrics $followups,
        private readonly MeetingMetrics $meetings,
    ) {
        parent::__construct($lookups);
    }

    public function sections(ReportQueries $q, ReportLinks $links): array
    {
        $p = $this->prev($q);
        $sections = [$this->kpis('sales', 'Leads & outcomes', $this->salesKpis($q, $p, $links), $p !== null)];

        $activity = [];
        if ($this->canSee($q, 'followup')) {
            $f = $this->followups->summary($q);
            $pf = $p ? $this->followups->summary($p) : null;
            $activity[] = Metric::kpi('followups_completed', 'Follow-ups completed', $f['completed_in_period'], 'number', 'Follow-ups marked completed in the period.', $pf['completed_in_period'] ?? null);
            $activity[] = Metric::kpi('followups_overdue', 'Overdue follow-ups (now)', $f['overdue_now'], 'number', 'Pending follow-ups past their scheduled time right now.', snapshot: true, link: $links->followups(['tab' => 'overdue'], false), higherIsBetter: false);
        }
        if ($this->canSee($q, 'meeting')) {
            $m = $this->meetings->summary($q);
            $pm = $p ? $this->meetings->summary($p) : null;
            $activity[] = Metric::kpi('meetings_completed', 'Meetings completed', $m['completed'], 'number', 'Meetings starting in the period that were completed.', $pm['completed'] ?? null, link: $links->meetings(['status' => 'completed']));
            $activity[] = Metric::kpi('meetings_upcoming', 'Upcoming meetings (now)', $m['upcoming_now'], 'number', 'Scheduled or confirmed meetings from now on.', snapshot: true, link: $links->meetings(['tab' => 'upcoming'], false));
        }
        if ($activity !== []) {
            $sections[] = $this->kpis('activity', 'Activity', $activity, $p !== null);
        }

        $created = $this->leads->createdTrend($q);
        $won = $this->leads->periodOutcomeTrend($q, 'won');
        $sections[] = $this->chart('trend', 'New leads vs leads won', 'line', $this->bucketLabels(array_keys($created), $q->filters->granularity()), [
            ['label' => 'New leads', 'data' => $created],
            ['label' => 'Won', 'data' => $won, 'color' => 'won'],
        ]);

        $sources = $this->leads->breakdown($q, 'leads.source_id', 8)->map(fn ($r) => [
            'name' => $this->lookups->sourceName($r->k),
            'leads' => $r->leads,
            'won' => $r->won,
            'win_rate' => Metric::rate($r->won, $r->leads),
            'won_value' => $r->won_value,
            '_links' => ['leads' => $links->leads(['source' => $r->k], true)],
        ])->all();
        $sections[] = $this->table('sources', 'Top sources (leads created in period)', [
            $this->col('name', 'Source', 'text'), $this->col('leads', 'Leads'), $this->col('won', 'Won now'),
            $this->col('win_rate', 'Cohort conversion', 'percent'), $this->col('won_value', 'Won value', 'currency'),
        ], $sources, more: $links->report('campaigns'));

        $pipeline = $this->pipeline->byStatus($q)->map(fn ($r) => [
            'name' => $this->lookups->statusName((int) $r->k),
            'leads' => (int) $r->leads,
            'value' => Metric::money($r->value),
            'no_value' => (int) $r->no_value,
            '_links' => ['leads' => $links->leads(['status' => $r->k])],
        ])->all();
        $sections[] = $this->table('pipeline', 'Open pipeline by status (now)', [
            $this->col('name', 'Status', 'text'), $this->col('leads', 'Open leads'), $this->col('value', 'Estimated value', 'currency'), $this->col('no_value', 'Without value'),
        ], $pipeline, $this->totals($pipeline, ['leads', 'value', 'no_value']), 'No open leads.', more: $links->report('pipeline'));

        return $sections;
    }

    private function salesKpis(ReportQueries $q, ?ReportQueries $p, ReportLinks $links): array
    {
        $cohort = $this->leads->cohortSummary($q);
        $won = $this->leads->periodOutcome($q, 'won');
        $lost = $this->leads->periodOutcome($q, 'lost');
        $pCohort = $p ? $this->leads->cohortSummary($p) : null;
        $pWon = $p ? $this->leads->periodOutcome($p, 'won') : null;
        $pLost = $p ? $this->leads->periodOutcome($p, 'lost') : null;
        $pipeline = $this->pipeline->totals($q);
        $resp = $this->response->summary($q);
        $pResp = $p ? $this->response->summary($p) : null;

        return [
            Metric::kpi('new_leads', 'New leads', $cohort['leads'], 'number', 'Leads created in the period (leads.created_at).', $pCohort['leads'] ?? null, link: $links->leads([], true)),
            Metric::kpi('won', 'Leads won', $won['count'], 'number', 'Leads moved to a won status in the period (status history), whenever they were created.', $pWon['count'] ?? null),
            Metric::kpi('won_value', 'Won value', $won['value'], 'currency', 'Sum of the estimated value of leads won in the period.', $pWon['value'] ?? null),
            Metric::kpi('lost', 'Leads lost', $lost['count'], 'number', 'Leads moved to a lost status in the period.', $pLost['count'] ?? null, higherIsBetter: false),
            Metric::kpi('win_rate', 'Win rate', $this->leads->periodWinRate($won, $lost), 'percent', 'Won ÷ (won + lost) for outcomes recorded in the period.', $p ? $this->leads->periodWinRate($pWon, $pLost) : null),
            Metric::kpi('cohort_conversion', 'Cohort conversion', $cohort['cohort_win_rate'], 'percent', 'Of leads created in the period, the share that is won today.', $pCohort['cohort_win_rate'] ?? null),
            Metric::kpi('pipeline_value', 'Open pipeline value (now)', Metric::money($pipeline->value ?? 0), 'currency',
                'Estimated value of open leads right now. '.((int) ($pipeline->no_value ?? 0)).' open leads have no value.', snapshot: true, link: $links->report('pipeline')),
            Metric::kpi('median_first_attempt', 'Median first response', $resp['median_attempt'], 'duration', 'Median time from lead creation to the first response attempt, for leads created in the period.', $pResp['median_attempt'] ?? null, higherIsBetter: false, link: $links->report('response-time')),
        ];
    }
}
