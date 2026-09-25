<?php

namespace App\Services\Reports\Definitions;

use App\Enums\CallStatus;
use App\Models\Call;
use App\Services\Reports\Metric;
use App\Services\Reports\Metrics\CallMetrics;
use App\Services\Reports\Metrics\LeadMetrics;
use App\Services\Reports\ReportLinks;
use App\Services\Reports\ReportLookups;
use App\Services\Reports\ReportQueries;
use App\Services\Reports\ReportScope;

class CallsReport extends ReportDefinition
{
    public const SLUG = 'calls';

    public const TITLE = 'Call analytics';

    public const CATEGORY = 'Activity';

    public const DESCRIPTION = 'Call volume, connection rate, talk time, dispositions and call outcomes.';

    public const FILTERS = ['date', 'user', 'source', 'campaign', 'status', 'priority', 'city', 'state', 'archived', 'compare'];

    public function __construct(ReportLookups $lookups, private readonly CallMetrics $calls, private readonly LeadMetrics $leads)
    {
        parent::__construct($lookups);
    }

    public function availableTo(ReportScope $scope): bool
    {
        return $scope->user->can('viewAny', Call::class);
    }

    public function sections(ReportQueries $q, ReportLinks $links): array
    {
        $p = $this->prev($q);
        $s = $this->calls->summary($q);
        $ps = $p ? $this->calls->summary($p) : null;
        $wonNow = $this->calls->leadsCalledNowWon($q);

        $sections = [
            $this->kpis('summary', 'Calls in the period', [
                Metric::kpi('total', 'Total calls', $s['total'], 'number', 'Calls started in the period.', $ps['total'] ?? null, link: $links->calls()),
                Metric::kpi('outbound', 'Outbound', $s['outbound'], 'number', null, $ps['outbound'] ?? null, link: $links->calls(['direction' => 'outbound'])),
                Metric::kpi('inbound', 'Inbound', $s['inbound'], 'number', null, $ps['inbound'] ?? null, link: $links->calls(['direction' => 'inbound'])),
                Metric::kpi('connected', 'Connected', $s['connected'], 'number', 'Answered or completed calls.', $ps['connected'] ?? null),
                Metric::kpi('connection_rate', 'Connection rate', $s['connection_rate'], 'percent', 'Connected outbound ÷ finished outbound (answered, completed, busy, no answer, failed, missed).', $ps['connection_rate'] ?? null),
                Metric::kpi('missed', 'Missed inbound', $s['missed_inbound'], 'number', 'Inbound calls missed or not answered.', $ps['missed_inbound'] ?? null, higherIsBetter: false),
                Metric::kpi('talk', 'Talk time', $s['talk_seconds'], 'duration', 'Provider-reported talk duration of connected calls.', $ps['talk_seconds'] ?? null),
                Metric::kpi('avg_talk', 'Average talk time', $s['avg_talk_seconds'], 'duration', 'Per connected call with a reported duration.', $ps['avg_talk_seconds'] ?? null),
                Metric::kpi('missing_disposition', 'Missing disposition', $s['missing_disposition'], 'number', 'Calls that require a disposition and have none.', $ps['missing_disposition'] ?? null, higherIsBetter: false, link: $links->calls(['missing_disposition' => 1])),
            ], $p !== null),
            $this->kpis('outcomes', 'What calls led to', [
                Metric::kpi('to_followup', 'Follow-ups scheduled from calls', $s['to_followup'], 'number', 'Calls linked to a follow-up created from the call.', $ps['to_followup'] ?? null),
                Metric::kpi('to_meeting', 'Meetings booked from calls', $s['to_meeting'], 'number', 'Calls linked to a meeting booked from the call.', $ps['to_meeting'] ?? null),
                Metric::kpi('leads_called', 'Leads called', $s['leads_called'], 'number', 'Distinct leads with a call in the period.', $ps['leads_called'] ?? null),
                Metric::kpi('called_won', 'Of those, won today', $wonNow, 'number', 'Leads called in the period that are currently won. Descriptive only — it does not mean the call caused the win.', snapshot: true),
            ], $p !== null),
        ];

        $connected = "'".implode("','", CallStatus::connectedValues())."'";
        $total = $this->leads->trend($this->calls->inPeriod($q), 'calls.started_at', $q);
        $conn = $this->leads->trend($this->calls->inPeriod($q), 'calls.started_at', $q, "SUM(CASE WHEN calls.status IN ({$connected}) THEN 1 ELSE 0 END)");
        $sections[] = $this->chart('trend', 'Calls over time', 'line', $this->bucketLabels(array_keys($total), $q->filters->granularity()), [
            ['label' => 'Calls', 'data' => $total], ['label' => 'Connected', 'data' => $conn],
        ]);

        $statuses = $this->calls->statusDistribution($q);
        $sections[] = $this->chart('status_chart', 'Call status', 'donut', array_map(fn ($k) => CallStatus::tryFrom($k)?->label() ?? $k, array_keys($statuses)), [
            ['label' => 'Calls', 'data' => array_values($statuses)],
        ]);

        $groups = [['by_agent', 'By agent', 'calls.agent_user_id', fn ($k) => $this->lookups->userName($k !== '' ? (int) $k : null, 'No agent'), 'agent']];
        foreach ($groups as [$key, $title, $col, $label, $param]) {
            $rows = $this->calls->by($q, $col)->map(fn ($r, $k) => [
                'name' => $label((string) $k), ...$r,
                '_links' => $k !== '' ? array_filter(['total' => $links->calls([$param => $k])]) : [],
            ])->sortBy('name')->values()->all();
            $sections[] = $this->table($key, $title, [
                $this->col('name', 'Agent', 'text'), $this->col('total', 'Calls'), $this->col('outbound', 'Outbound'), $this->col('inbound', 'Inbound'),
                $this->col('connected', 'Connected'), $this->col('connection_rate', 'Connection rate', 'percent'), $this->col('missed_inbound', 'Missed inbound'),
                $this->col('talk_seconds', 'Talk time', 'duration'), $this->col('avg_talk_seconds', 'Avg talk', 'duration'), $this->col('missing_disposition', 'Missing disposition'),
                $this->col('to_followup', '→ Follow-up'), $this->col('to_meeting', '→ Meeting'),
            ], $rows, $this->totals($rows, ['total', 'outbound', 'inbound', 'connected', 'missed_inbound', 'talk_seconds', 'missing_disposition', 'to_followup', 'to_meeting'], extra: [
                'connection_rate' => $s['connection_rate'], 'avg_talk_seconds' => $s['avg_talk_seconds'],
            ]), 'No calls in the selected period.', 'Calls are attributed to the agent recorded on each call.');
        }

        $dispositions = $this->calls->dispositionDistribution($q)->map(fn ($r) => [
            'name' => $this->lookups->dispositionName($r->k ? (int) $r->k : null),
            'calls' => (int) $r->c,
            'share' => Metric::rate($r->c, $s['connected']),
            '_links' => $r->k ? ['calls' => $links->calls(['disposition' => $r->k])] : [],
        ])->all();
        $sections[] = $this->table('dispositions', 'Dispositions (connected calls)', [
            $this->col('name', 'Disposition', 'text'), $this->col('calls', 'Calls'), $this->col('share', 'Share', 'percent'),
        ], $dispositions, null, 'No connected calls in the selected period.');

        return $sections;
    }
}
