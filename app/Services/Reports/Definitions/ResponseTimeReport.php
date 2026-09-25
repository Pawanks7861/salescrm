<?php

namespace App\Services\Reports\Definitions;

use App\Services\Reports\Metric;
use App\Services\Reports\Metrics\ResponseMetrics;
use App\Services\Reports\ReportLinks;
use App\Services\Reports\ReportLookups;
use App\Services\Reports\ReportQueries;
use App\Services\Reports\ReportScope;

class ResponseTimeReport extends ReportDefinition
{
    public const SLUG = 'response-time';

    public const TITLE = 'Response time & speed to lead';

    public const CATEGORY = 'Activity';

    public const DESCRIPTION = 'How quickly new leads get a first response attempt and a first real contact.';

    public const FILTERS = ['date', 'user', 'source', 'campaign', 'priority', 'city', 'state', 'archived', 'compare'];

    public function __construct(ReportLookups $lookups, private readonly ResponseMetrics $response)
    {
        parent::__construct($lookups);
    }

    public function sections(ReportQueries $q, ReportLinks $links): array
    {
        $p = $this->prev($q);
        $s = $this->response->summary($q);
        $ps = $p ? $this->response->summary($p) : null;
        $target = $s['target_minutes'];

        $sections = [
            $this->kpis('summary', 'Leads created in the period', [
                Metric::kpi('leads', 'Leads created', $s['leads'], 'number', 'Acquisition cohort.', $ps['leads'] ?? null, link: $links->leads([], true)),
                Metric::kpi('median_attempt', 'Median first response attempt', $s['median_attempt'], 'duration', 'Creation → first outbound call, completed follow-up or contact.', $ps['median_attempt'] ?? null, higherIsBetter: false),
                Metric::kpi('median_contact', 'Median first contact', $s['median_contact'], 'duration', 'Creation → first connected call, or follow-up / meeting completed with a contact outcome.', $ps['median_contact'] ?? null, higherIsBetter: false),
                Metric::kpi('within_target', "Attempted within {$target} min", $s['within_target_rate'], 'percent', "Share of created leads with a first attempt within {$target} minutes (setting report.response_target_minutes).", $ps['within_target_rate'] ?? null),
                Metric::kpi('contact_rate', 'Contacted', $s['contact_rate'], 'percent', 'Share of created leads reached so far.', $ps['contact_rate'] ?? null),
                Metric::kpi('no_attempt', 'No response yet', $s['no_attempt'], 'number', 'Created leads with no response attempt so far.', $ps['no_attempt'] ?? null, higherIsBetter: false),
            ], $p !== null),
            $this->chart('buckets_chart', 'Time to first response', 'bar', array_column($s['buckets'], 'label'), [
                ['label' => 'First attempt', 'data' => array_column($s['buckets'], 'attempt')],
                ['label' => 'First contact', 'data' => array_column($s['buckets'], 'contact')],
            ]),
            $this->table('buckets', 'Response time distribution', [
                $this->col('label', 'Time after creation', 'text'), $this->col('attempt', 'First attempt'), $this->col('contact', 'First contact'),
            ], $s['buckets'], null, 'No leads were created in the selected period.'),
            $this->table('percentiles', 'Averages and percentiles', [
                $this->col('name', 'Measure', 'text'), $this->col('avg', 'Average', 'duration'), $this->col('median', 'Median', 'duration'), $this->col('p90', '90th percentile', 'duration'),
            ], $s['leads'] ? [
                ['name' => 'First response attempt', 'avg' => $s['avg_attempt'], 'median' => $s['median_attempt'], 'p90' => $s['p90_attempt']],
                ['name' => 'First contact', 'avg' => $s['avg_contact'], 'median' => $s['median_contact'], 'p90' => $s['p90_contact']],
            ] : [], null, 'No leads were created in the selected period.', 'Only leads that have had a response are included in averages and percentiles.'),
        ];

        if ($q->scope->tier !== ReportScope::OWN) {
            $rows = $this->response->by($q)->map(fn ($r, $k) => [
                'name' => $this->lookups->userName($k !== '' ? (int) $k : null),
                'leads' => (int) $r->leads,
                'attempted' => (int) $r->attempted,
                'contacted' => (int) $r->contacted,
                'avg_attempt' => $r->avg_attempt !== null ? (int) round($r->avg_attempt) : null,
                'avg_contact' => $r->avg_contact !== null ? (int) round($r->avg_contact) : null,
                'within_target' => Metric::rate($r->within_target, $r->leads),
            ])->sortBy('name')->values()->all();
            $sections[] = $this->table('by_owner', 'By current owner', [
                $this->col('name', 'Owner', 'text'), $this->col('leads', 'Leads'), $this->col('attempted', 'Attempted'), $this->col('contacted', 'Contacted'),
                $this->col('avg_attempt', 'Avg first attempt', 'duration'), $this->col('avg_contact', 'Avg first contact', 'duration'), $this->col('within_target', "Within {$target} min", 'percent'),
            ], $rows, null, 'No leads were created in the selected period.', 'Attributed to the lead\'s current owner; the response itself may have been made by anyone.');
        }

        $meta = $this->response->metaSpeed($q);
        $sections[] = $this->table('meta_speed', 'Meta lead ads — speed to lead ('.$meta['leads'].' leads)', [
            $this->col('label', 'Stage', 'text'), $this->col('avg', 'Average', 'duration'), $this->col('median', 'Median', 'duration'),
        ], $meta['leads'] ? $meta['stages'] : [], null, 'No Meta leads were created in the selected period.',
            'Ingestion uses the created time reported by Meta on the webhook event; it is blank for leads without an event record.');

        return $sections;
    }
}
