<?php

namespace App\Services\Reports\Definitions;

use App\Services\Reports\Metric;
use App\Services\Reports\Metrics\LeadMetrics;
use App\Services\Reports\ReportLinks;
use App\Services\Reports\ReportLookups;
use App\Services\Reports\ReportQueries;

class CampaignsReport extends ReportDefinition
{
    public const SLUG = 'campaigns';

    public const TITLE = 'Sources, campaigns & Meta';

    public const CATEGORY = 'Marketing';

    public const DESCRIPTION = 'Lead volume and conversion by source and campaign, plus Meta lead-form enquiries.';

    public const FILTERS = ['date', 'user', 'source', 'campaign', 'priority', 'city', 'state', 'archived'];

    public function __construct(ReportLookups $lookups, private readonly LeadMetrics $leads)
    {
        parent::__construct($lookups);
    }

    public function sections(ReportQueries $q, ReportLinks $links): array
    {
        $sections = [];
        $columns = fn (string $label) => [
            $this->col('name', $label, 'text'), $this->col('leads', 'Leads'), $this->col('open', 'Open'), $this->col('won', 'Won now'),
            $this->col('lost', 'Lost now'), $this->col('conversion', 'Cohort conversion', 'percent'), $this->col('won_value', 'Won value', 'currency'),
            $this->col('open_value', 'Open value', 'currency'),
        ];
        $map = fn (string $param, callable $label) => fn ($r) => [
            'name' => $label($r->k), 'leads' => $r->leads, 'open' => $r->open, 'won' => $r->won, 'lost' => $r->lost,
            'conversion' => Metric::rate($r->won, $r->leads), 'won_value' => $r->won_value, 'open_value' => $r->open_value,
            '_links' => ['leads' => $r->k ? $links->leads([$param => $r->k], true) : null],
        ];

        $sources = $this->leads->breakdown($q, 'leads.source_id')->map($map('source', fn ($k) => $this->lookups->sourceName((int) $k)))->all();
        $sections[] = $this->chart('sources_chart', 'Leads by source', 'bar', array_column($sources, 'name'), [
            ['label' => 'Leads', 'data' => array_column($sources, 'leads')], ['label' => 'Won now', 'data' => array_column($sources, 'won'), 'color' => 'won'],
        ]);
        $sections[] = $this->table('sources', 'By source', $columns('Source'), $sources,
            $this->totals($sources, ['leads', 'open', 'won', 'lost', 'won_value', 'open_value'], extra: ['conversion' => Metric::rate(array_sum(array_column($sources, 'won')), array_sum(array_column($sources, 'leads')))]),
            'No leads were created in the selected period.', 'Leads created in the period and their status today (acquisition cohort).');

        $campaigns = $this->leads->breakdown($q, 'leads.campaign_id')->map($map('campaign', fn ($k) => $this->lookups->campaignName($k ? (int) $k : null)))->all();
        $sections[] = $this->table('campaigns', 'By campaign', $columns('Campaign'), $campaigns,
            $this->totals($campaigns, ['leads', 'open', 'won', 'lost', 'won_value', 'open_value'], extra: ['conversion' => Metric::rate(array_sum(array_column($campaigns, 'won')), array_sum(array_column($campaigns, 'leads')))]),
            'No leads were created in the selected period.');

        $enquiries = $q->enquiries()->where('lead_enquiries.channel', 'facebook')
            ->whereBetween('lead_enquiries.received_at', [$q->filters->from, $q->filters->to]);
        $e = (clone $enquiries)->toBase()->selectRaw('COUNT(*) AS enquiries, COUNT(DISTINCT lead_enquiries.lead_id) AS leads')->first();
        $total = (int) ($e->enquiries ?? 0);
        $unique = (int) ($e->leads ?? 0);

        $sections[] = $this->kpis('meta', 'Meta lead forms', [
            Metric::kpi('enquiries', 'Enquiries', $total, 'number', 'Meta lead-form submissions received in the period (one per submission).'),
            Metric::kpi('unique', 'Unique leads', $unique, 'number', 'Distinct CRM leads those submissions belong to.'),
            Metric::kpi('repeat', 'Repeat enquiries', $total - $unique, 'number', 'Submissions from people who already had a lead (enquiries − unique leads).'),
        ], note: 'Enquiries and leads are counted separately: a person who submits two forms is one lead with two enquiries.');

        $forms = (clone $enquiries)->join('leads as el', 'el.id', '=', 'lead_enquiries.lead_id')->toBase()
            ->selectRaw('el.facebook_form_id AS k, COUNT(*) AS enquiries, COUNT(DISTINCT lead_enquiries.lead_id) AS leads')
            ->groupBy('el.facebook_form_id')->orderByDesc('enquiries')->get()
            ->map(fn ($r) => [
                'name' => $this->lookups->formName($r->k), 'enquiries' => (int) $r->enquiries, 'leads' => (int) $r->leads,
                'repeat' => (int) $r->enquiries - (int) $r->leads,
                '_links' => ['leads' => $r->k ? $links->leads(['facebook_form' => $r->k]) : null],
            ])->all();
        $sections[] = $this->table('meta_forms', 'Meta enquiries by form', [
            $this->col('name', 'Form', 'text'), $this->col('enquiries', 'Enquiries'), $this->col('leads', 'Unique leads'), $this->col('repeat', 'Repeat'),
        ], $forms, $this->totals($forms, ['enquiries', 'leads', 'repeat']), 'No Meta enquiries in the selected period.',
            'Form = the form that created the lead.');

        return $sections;
    }
}
