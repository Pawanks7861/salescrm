<?php

namespace App\Services\Reports\Definitions;

use App\Services\Reports\Metric;
use App\Services\Reports\Metrics\CallMetrics;
use App\Services\Reports\Metrics\FollowupMetrics;
use App\Services\Reports\Metrics\LeadMetrics;
use App\Services\Reports\Metrics\MeetingMetrics;
use App\Services\Reports\Metrics\PipelineMetrics;
use App\Services\Reports\Metrics\ResponseMetrics;
use App\Services\Reports\ReportLinks;
use App\Services\Reports\ReportLookups;
use App\Services\Reports\ReportQueries;
use Illuminate\Support\Collection;

/**
 * Factual per-salesperson metrics side by side. There is deliberately no
 * composite score, rank or "top performer" label; rows are ordered by name.
 * Company scope compares every salesperson; own scope shows only the
 * viewer's row.
 */
class SalesPerformanceReport extends ReportDefinition
{
    public const SLUG = 'sales-performance';

    public const TITLE = 'Salesperson performance';

    public const CATEGORY = 'People';

    public const DESCRIPTION = 'Leads, outcomes, calls, follow-ups, meetings and response speed per salesperson.';

    public const FILTERS = ['date', 'user', 'source', 'campaign', 'priority', 'city', 'state', 'archived'];

    public function __construct(
        ReportLookups $lookups,
        protected readonly LeadMetrics $leads,
        protected readonly PipelineMetrics $pipeline,
        protected readonly ResponseMetrics $response,
        protected readonly CallMetrics $calls,
        protected readonly FollowupMetrics $followups,
        protected readonly MeetingMetrics $meetings,
    ) {
        parent::__construct($lookups);
    }

    /** Grouping columns: [leads, calls, follow-ups, meetings]. */
    protected function columns(): array
    {
        return ['leads.assigned_to', 'calls.agent_user_id', 'followups.assigned_to', 'meetings.host_user_id'];
    }

    /** @return Collection<int|string, string> row id → label */
    protected function subjects(ReportQueries $q): Collection
    {
        return $q->scope->users()
            ->when($q->filters->userId, fn ($b, $id) => $b->whereKey($id))
            ->get(['id', 'name', 'is_active'])
            ->mapWithKeys(fn ($u) => [(string) $u->id => $u->name.($u->is_active ? '' : ' (inactive)')]);
    }

    protected function subjectLabel(): string
    {
        return 'Salesperson';
    }

    protected function linkParam(): array
    {
        return ['leads' => 'assignee', 'calls' => 'agent', 'followups' => 'assigned_to', 'meetings' => 'host'];
    }

    protected function noneLabel(): string
    {
        return 'Unassigned';
    }

    public function sections(ReportQueries $q, ReportLinks $links): array
    {
        [$leadCol, $callCol, $fuCol, $mtgCol] = $this->columns();

        $open = $this->pipeline->by($q, $leadCol);
        $cohort = $this->leads->breakdown($q, $leadCol)->keyBy(fn ($r) => (string) ($r->k ?? ''));
        $won = $this->leads->periodOutcomeBy($q, 'won', $leadCol);
        $lost = $this->leads->periodOutcomeBy($q, 'lost', $leadCol);
        $resp = $this->response->by($q);
        $calls = $this->canSee($q, 'call') ? $this->calls->by($q, $callCol) : collect();
        $fus = $this->canSee($q, 'followup') ? $this->followups->by($q, $fuCol) : collect();
        $mtgs = $this->canSee($q, 'meeting') ? $this->meetings->by($q, $mtgCol) : collect();

        $subjects = $this->subjects($q);
        $ids = $subjects->keys()->all();
        $hasNone = collect([$open, $cohort, $won, $lost])->contains(fn ($c) => $c->has(''));
        if ($hasNone && ! $q->filters->userId) {
            $ids[] = '';
        }

        $param = $this->linkParam();
        $rows = [];
        foreach ($ids as $id) {
            $c = $calls[$id] ?? null;
            $f = $fus[$id] ?? null;
            $m = $mtgs[$id] ?? null;
            $w = (int) ($won[$id]->c ?? 0);
            $l = (int) ($lost[$id]->c ?? 0);

            $row = [
                'name' => $id === '' ? $this->noneLabel() : $subjects[$id],
                'open_now' => (int) ($open[$id]->leads ?? 0),
                'new_leads' => (int) ($cohort[$id]->leads ?? 0),
                'won' => $w,
                'won_value' => Metric::money($won[$id]->v ?? 0),
                'lost' => $l,
                'win_rate' => Metric::rate($w, $w + $l),
                'avg_first_attempt' => isset($resp[$id]) && $resp[$id]->avg_attempt !== null ? (int) round($resp[$id]->avg_attempt) : null,
                'calls' => $c['outbound'] ?? 0,
                'connected' => $c['connected'] ?? 0,
                'talk_seconds' => $c['talk_seconds'] ?? 0,
                'followups_completed' => $f['completed_in_period'] ?? 0,
                'overdue_now' => $f['overdue_now'] ?? 0,
                'meetings_completed' => $m['completed'] ?? 0,
                '_links' => $id === '' ? [] : array_filter([
                    'open_now' => $links->leads([$param['leads'] => $id]),
                    'new_leads' => $links->leads([$param['leads'] => $id], true),
                    'calls' => $links->calls([$param['calls'] => $id]),
                    'overdue_now' => $links->followups(['tab' => 'overdue', $param['followups'] => $id], false),
                    'meetings_completed' => $links->meetings([$param['meetings'] => $id, 'status' => 'completed']),
                ]),
                '_muted' => $id !== '' && str_ends_with($subjects[$id] ?? '', '(inactive)'),
            ];

            $activity = $row['open_now'] + $row['new_leads'] + $w + $l + $row['calls'] + $row['followups_completed'] + $row['overdue_now'] + $row['meetings_completed'];
            if ($activity === 0 && ($id === '' || $row['_muted'])) {
                continue;
            }
            $rows[] = $row;
        }

        $columns = [
            $this->col('name', $this->subjectLabel(), 'text'),
            $this->col('open_now', 'Open leads (now)'),
            $this->col('new_leads', 'New leads'),
            $this->col('won', 'Won'),
            $this->col('won_value', 'Won value', 'currency'),
            $this->col('lost', 'Lost'),
            $this->col('win_rate', 'Win rate', 'percent'),
            $this->col('avg_first_attempt', 'Avg first response', 'duration'),
        ];
        if ($this->canSee($q, 'call')) {
            array_push($columns, $this->col('calls', 'Outbound calls'), $this->col('connected', 'Connected'), $this->col('talk_seconds', 'Talk time', 'duration'));
        }
        if ($this->canSee($q, 'followup')) {
            array_push($columns, $this->col('followups_completed', 'Follow-ups done'), $this->col('overdue_now', 'Overdue (now)'));
        }
        if ($this->canSee($q, 'meeting')) {
            $columns[] = $this->col('meetings_completed', 'Meetings done');
        }

        $sumKeys = ['open_now', 'new_leads', 'won', 'won_value', 'lost', 'calls', 'connected', 'talk_seconds', 'followups_completed', 'overdue_now', 'meetings_completed'];
        $totals = $this->totals($rows, $sumKeys, extra: [
            'win_rate' => Metric::rate(array_sum(array_column($rows, 'won')), array_sum(array_column($rows, 'won')) + array_sum(array_column($rows, 'lost'))),
            'avg_first_attempt' => null,
        ]);

        $wonChart = collect($rows)->filter(fn ($r) => $r['won'] || $r['lost']);

        return [
            $this->table('performance', static::TITLE, $columns, $rows, $totals, 'No activity for the selected filters.',
                'Lead columns use the lead\'s current owner. Won / lost = status changes in the period. Calls, follow-ups and meetings use the agent / assignee / host on each record. Open leads and overdue follow-ups are "now" snapshots. Factual metrics only — no score or ranking.'),
            $this->chart('outcomes', 'Won vs lost in the period', 'stacked', $wonChart->pluck('name')->all(), [
                ['label' => 'Won', 'data' => $wonChart->pluck('won')->all(), 'color' => 'won'],
                ['label' => 'Lost', 'data' => $wonChart->pluck('lost')->all(), 'color' => 'lost'],
            ]),
        ];
    }
}
