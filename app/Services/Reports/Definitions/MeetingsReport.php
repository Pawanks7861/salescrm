<?php

namespace App\Services\Reports\Definitions;

use App\Enums\MeetingOutcome;
use App\Models\Meeting;
use App\Services\Reports\Metric;
use App\Services\Reports\Metrics\LeadMetrics;
use App\Services\Reports\Metrics\MeetingMetrics;
use App\Services\Reports\ReportLinks;
use App\Services\Reports\ReportLookups;
use App\Services\Reports\ReportQueries;
use App\Services\Reports\ReportScope;

class MeetingsReport extends ReportDefinition
{
    public const SLUG = 'meetings';

    public const TITLE = 'Meeting analytics';

    public const CATEGORY = 'Activity';

    public const DESCRIPTION = 'Meetings scheduled, completed, cancelled and no-shows, with outcomes.';

    public const FILTERS = ['date', 'user', 'source', 'campaign', 'status', 'priority', 'city', 'state', 'archived', 'compare'];

    public function __construct(ReportLookups $lookups, private readonly MeetingMetrics $meetings, private readonly LeadMetrics $leads)
    {
        parent::__construct($lookups);
    }

    public function availableTo(ReportScope $scope): bool
    {
        return $scope->user->can('viewAny', Meeting::class);
    }

    public function sections(ReportQueries $q, ReportLinks $links): array
    {
        $p = $this->prev($q);
        $s = $this->meetings->summary($q);
        $ps = $p ? $this->meetings->summary($p) : null;
        $met = $this->meetings->leadsMetNowWon($q);

        $sections = [
            $this->kpis('summary', 'Meetings starting in the period', [
                Metric::kpi('scheduled', 'Meetings', $s['scheduled'], 'number', 'Meetings with a start time in the period (rescheduled originals excluded).', $ps['scheduled'] ?? null, link: $links->meetings()),
                Metric::kpi('completed', 'Completed', $s['completed'], 'number', null, $ps['completed'] ?? null, link: $links->meetings(['status' => 'completed'])),
                Metric::kpi('completion_rate', 'Completion rate', $s['completion_rate'], 'percent', 'Completed ÷ (completed + cancelled + no-show).', $ps['completion_rate'] ?? null),
                Metric::kpi('no_show', 'No-shows', $s['no_show'], 'number', null, $ps['no_show'] ?? null, higherIsBetter: false, link: $links->meetings(['status' => 'no_show'])),
                Metric::kpi('no_show_rate', 'No-show rate', $s['no_show_rate'], 'percent', 'No-show ÷ (completed + no-show).', $ps['no_show_rate'] ?? null, higherIsBetter: false),
                Metric::kpi('cancelled', 'Cancelled', $s['cancelled'], 'number', null, $ps['cancelled'] ?? null, higherIsBetter: false, link: $links->meetings(['status' => 'cancelled'])),
                Metric::kpi('rescheduled', 'Rescheduled', $s['rescheduled'], 'number', 'Meetings moved to a new time (the new meeting is counted above).', $ps['rescheduled'] ?? null),
                Metric::kpi('awaiting', 'Awaiting update', $s['awaiting_update'], 'number', 'Ended but not yet marked completed, cancelled or no-show.', higherIsBetter: false, snapshot: true),
                Metric::kpi('upcoming_now', 'Upcoming (now)', $s['upcoming_now'], 'number', 'Scheduled or confirmed meetings from now on.', snapshot: true, link: $links->meetings(['tab' => 'upcoming'], false)),
                Metric::kpi('met_won', 'Leads met, won today', $met['won'], 'number', "Of {$met['leads']} leads with a completed meeting in the period, how many are currently won. Descriptive only.", snapshot: true),
            ], $p !== null),
        ];

        $all = $this->leads->trend($this->meetings->inPeriod($q), 'meetings.start_at', $q);
        $done = $this->leads->trend($this->meetings->inPeriod($q), 'meetings.start_at', $q, "SUM(CASE WHEN meetings.status = 'completed' THEN 1 ELSE 0 END)");
        $sections[] = $this->chart('trend', 'Meetings over time', 'line', $this->bucketLabels(array_keys($all), $q->filters->granularity()), [
            ['label' => 'Meetings', 'data' => $all], ['label' => 'Completed', 'data' => $done],
        ]);

        $groups = [['by_host', 'By host', 'meetings.host_user_id', fn ($k) => $this->lookups->userName($k !== '' ? (int) $k : null, 'No host'), 'host']];
        $groups[] = ['by_type', 'By type', 'meetings.meeting_type_id', fn ($k) => $this->lookups->meetingTypeName($k !== '' ? (int) $k : null), 'type'];

        foreach ($groups as [$key, $title, $col, $label, $param]) {
            $rows = $this->meetings->by($q, $col)->map(fn ($r, $k) => [
                'name' => $label((string) $k), ...$r,
                '_links' => $k !== '' ? array_filter(['scheduled' => $links->meetings([$param => $k])]) : [],
            ])->sortBy('name')->values()->all();
            $sections[] = $this->table($key, $title, [
                $this->col('name', $key === 'by_type' ? 'Type' : 'Host', 'text'), $this->col('scheduled', 'Meetings'), $this->col('completed', 'Completed'),
                $this->col('cancelled', 'Cancelled'), $this->col('no_show', 'No-show'), $this->col('completion_rate', 'Completion rate', 'percent'), $this->col('no_show_rate', 'No-show rate', 'percent'),
            ], $rows, $this->totals($rows, ['scheduled', 'completed', 'cancelled', 'no_show'], extra: [
                'completion_rate' => $s['completion_rate'], 'no_show_rate' => $s['no_show_rate'],
            ]), 'No meetings in the selected period.');
        }

        $outcomes = $this->meetings->outcomes($q);
        $total = array_sum($outcomes);
        $rows = array_map(fn ($o, $c) => [
            'name' => MeetingOutcome::tryFrom((string) $o)?->label() ?? 'Not recorded', 'count' => $c, 'share' => Metric::rate($c, $total),
        ], array_keys($outcomes), $outcomes);
        $sections[] = $this->table('outcomes', 'Outcomes of completed meetings', [
            $this->col('name', 'Outcome', 'text'), $this->col('count', 'Meetings'), $this->col('share', 'Share', 'percent'),
        ], $rows, null, 'No meetings were completed in the selected period.');

        return $sections;
    }
}
