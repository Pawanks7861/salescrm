<?php

namespace App\Services\Reports\Metrics;

use App\Enums\MeetingStatus;
use App\Services\Reports\ReportQueries;
use App\Services\Reports\ReportSql;
use App\Services\SettingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Current-state views of open leads (not won, not lost, not archived). These
 * are snapshots "as of now" and ignore the date range; the historical funnel
 * lives in ConversionMetrics.
 *
 * Pipeline value uses leads.estimated_value; leads without a value are
 * counted and disclosed, never guessed. "Weighted value" multiplies by the
 * probability an admin configured on each status (not a prediction).
 */
class PipelineMetrics
{
    /** [key, label, min days, max days (inclusive) or null] */
    public const AGE_BUCKETS = [
        ['0_1', '0–1 days', 0, 1],
        ['2_3', '2–3 days', 2, 3],
        ['4_7', '4–7 days', 4, 7],
        ['8_15', '8–15 days', 8, 15],
        ['16_30', '16–30 days', 16, 30],
        ['31_plus', '31+ days', 31, null],
    ];

    public const NEGLECT_REASONS = [
        'untouched' => 'New lead not yet attempted',
        'overdue' => 'Has an overdue follow-up',
        'no_next_action' => 'No upcoming follow-up or meeting',
        'inactive' => 'No contact recently',
    ];

    public function __construct(private readonly SettingService $settings) {}

    /** @return Collection<int, object{k: int, leads: int, value: float, no_value: int, weighted: float}> */
    public function byStatus(ReportQueries $q): Collection
    {
        return $this->valueAggregates($q->openLeads()->join('lead_statuses as ps', 'ps.id', '=', 'leads.status_id'))
            ->selectRaw('leads.status_id AS k, MIN(ps.sort_order) AS sort')
            ->groupBy('leads.status_id')->orderBy('sort')
            ->toBase()->get();
    }

    /** @return Collection<string, object> keyed by owner id */
    public function by(ReportQueries $q, string $column): Collection
    {
        return $this->valueAggregates($q->openLeads()->join('lead_statuses as ps', 'ps.id', '=', 'leads.status_id'))
            ->selectRaw("{$column} AS k")->groupBy($column)
            ->toBase()->get()->keyBy(fn ($r) => (string) ($r->k ?? ''));
    }

    public function totals(ReportQueries $q): object
    {
        return $this->valueAggregates($q->openLeads()->join('lead_statuses as ps', 'ps.id', '=', 'leads.status_id'))->toBase()->first();
    }

    private function valueAggregates(Builder $query): Builder
    {
        return $query
            ->selectRaw('COUNT(*) AS leads')
            ->selectRaw('SUM(COALESCE(leads.estimated_value, 0)) AS value')
            ->selectRaw('SUM(CASE WHEN leads.estimated_value IS NULL THEN 1 ELSE 0 END) AS no_value')
            ->selectRaw('SUM(COALESCE(leads.estimated_value, 0) * ps.probability / 100) AS weighted');
    }

    /** Age expression (whole days since created_at). */
    private function ageDays(string $column = 'leads.created_at'): string
    {
        return '('.ReportSql::diffSeconds($column, ReportSql::at(now()->toImmutable())).' / 86400)';
    }

    private function bucketCase(string $days): string
    {
        $case = 'CASE';
        foreach (self::AGE_BUCKETS as [$key, , $min, $max]) {
            $case .= $max === null ? " WHEN {$days} >= {$min} THEN '{$key}'" : " WHEN {$days} BETWEEN {$min} AND {$max} THEN '{$key}'";
        }

        return $case." ELSE '0_1' END";
    }

    /**
     * Open-lead ageing (days since created) grouped by a column.
     *
     * @return Collection<int, object{g: mixed, b: string, c: int}>
     */
    public function ageing(ReportQueries $q, string $groupColumn = 'leads.status_id'): Collection
    {
        $days = $this->ageDays();
        $sub = $q->openLeads()->toBase()->select("{$groupColumn} as g")->selectRaw(ReportSql::floor($days).' AS d');

        return DB::query()->fromSub($sub, 'a')
            ->selectRaw('a.g, '.$this->bucketCase('a.d').' AS b, COUNT(*) AS c')
            ->groupBy('a.g', 'b')
            ->get();
    }

    /**
     * Days in the current status for open leads (since their last status
     * change; leads without history use created_at).
     *
     * @return Collection<int, object{k: int, leads: int, avg_days: float, max_days: float}>
     */
    public function timeInStatus(ReportQueries $q): Collection
    {
        $since = 'COALESCE((SELECT MAX(sc.changed_at) FROM lead_status_changes sc WHERE sc.lead_id = leads.id), leads.created_at)';
        $sub = $q->openLeads()->toBase()->select('leads.status_id as k')->selectRaw($this->ageDays($since).' AS d');

        return DB::query()->fromSub($sub, 't')
            ->selectRaw('t.k, COUNT(*) AS leads, AVG(t.d) AS avg_days, MAX(t.d) AS max_days')
            ->groupBy('t.k')->get();
    }

    public function untouchedHours(): int
    {
        return max(1, (int) $this->settings->get('report.untouched_new_lead_hours', 4));
    }

    public function inactiveDays(): int
    {
        return max(1, (int) $this->settings->get('report.inactive_days', 7));
    }

    /**
     * Neglected open leads, derived at query time (nothing is stored or
     * flagged on the lead). A lead can match several reasons.
     */
    public function neglected(ReportQueries $q): Builder
    {
        $now = ReportSql::at(now()->toImmutable());
        $untouchedBefore = ReportSql::at(now()->subHours($this->untouchedHours())->toImmutable());
        $inactiveBefore = ReportSql::at(now()->subDays($this->inactiveDays())->toImmutable());
        $open = "'".implode("','", MeetingStatus::openValues())."'";

        $noAttempt = "NOT EXISTS (SELECT 1 FROM calls nc WHERE nc.lead_id = leads.id AND (nc.direction = 'outbound' OR nc.status IN ('answered','completed')))
            AND NOT EXISTS (SELECT 1 FROM followups nf WHERE nf.lead_id = leads.id AND nf.status = 'completed' AND nf.deleted_at IS NULL)
            AND NOT EXISTS (SELECT 1 FROM meetings nm WHERE nm.lead_id = leads.id AND nm.status = 'completed' AND nm.deleted_at IS NULL)";
        $overdue = "EXISTS (SELECT 1 FROM followups odf WHERE odf.lead_id = leads.id AND odf.status = 'pending' AND odf.deleted_at IS NULL AND odf.scheduled_at < {$now})";
        $nextAction = "EXISTS (SELECT 1 FROM followups pf WHERE pf.lead_id = leads.id AND pf.status = 'pending' AND pf.deleted_at IS NULL AND pf.scheduled_at >= {$now})
            OR EXISTS (SELECT 1 FROM meetings um WHERE um.lead_id = leads.id AND um.status IN ({$open}) AND um.deleted_at IS NULL AND um.end_at >= {$now})";

        $flags = [
            'untouched' => "(leads.created_at < {$untouchedBefore} AND {$noAttempt})",
            'overdue' => "({$overdue})",
            'no_next_action' => "(NOT ({$nextAction}))",
            'inactive' => "(COALESCE(leads.last_contacted_at, leads.created_at) < {$inactiveBefore})",
        ];

        $query = $q->openLeads()->select('leads.*');
        foreach ($flags as $key => $sql) {
            $query->selectRaw("CASE WHEN {$sql} THEN 1 ELSE 0 END AS n_{$key}");
        }

        return $query->where(fn (Builder $w) => $w->whereRaw(implode(' OR ', $flags)));
    }

    /** Counts per reason, overall and per owner. */
    public function neglectedCounts(ReportQueries $q): array
    {
        $sub = $this->neglected($q)->toBase();
        $sums = implode(', ', array_map(fn ($k) => "SUM(n.n_{$k}) AS {$k}", array_keys(self::NEGLECT_REASONS)));

        $total = DB::query()->fromSub($sub, 'n')->selectRaw("COUNT(*) AS total, {$sums}")->first();
        $byOwner = DB::query()->fromSub($sub, 'n')->selectRaw("n.assigned_to AS k, COUNT(*) AS total, {$sums}")
            ->groupBy('n.assigned_to')->get();

        return ['total' => $total, 'by_owner' => $byOwner];
    }
}
