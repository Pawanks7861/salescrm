<?php

namespace App\Services\Reports\Metrics;

use App\Models\LeadStatus;
use App\Services\Reports\Metric;
use App\Services\Reports\ReportLookups;
use App\Services\Reports\ReportQueries;
use App\Services\Reports\ReportSql;
use Illuminate\Support\Facades\DB;

/**
 * Funnel, stage conversion, stage duration and conversion timing. Everything
 * historical is read from lead_status_changes; nothing is inferred from the
 * current status except "the lead is currently at stage X" (which is a fact).
 */
class ConversionMetrics
{
    public function __construct(private readonly ReportLookups $lookups) {}

    /**
     * Cohort funnel: of leads created in the period, how many reached (or
     * passed) each non-lost stage. "Reached" = highest non-lost stage in the
     * lead's status history, or its current stage when that is not lost.
     *
     * @return array<int, array{status_id: int, name: string, reached: int, rate_from_start: ?float, rate_from_previous: ?float}>
     */
    public function funnel(ReportQueries $q): array
    {
        $stages = $this->lookups->stages();
        if ($stages->isEmpty()) {
            return [];
        }

        $perLead = $q->cohort()
            ->leftJoin('lead_status_changes as h', 'h.lead_id', '=', 'leads.id')
            ->leftJoin('lead_statuses as hs', fn ($j) => $j->on('hs.id', '=', 'h.to_status_id')->where('hs.is_lost', false))
            ->join('lead_statuses as cs', 'cs.id', '=', 'leads.status_id')
            ->groupBy('leads.id')
            ->selectRaw('leads.id')
            ->selectRaw('MAX(hs.sort_order) AS h_sort')
            ->selectRaw('MAX(CASE WHEN cs.is_lost = 0 THEN cs.sort_order END) AS c_sort')
            ->toBase();

        $outer = DB::query()->fromSub($perLead, 'r')->selectRaw('COUNT(*) AS total');
        foreach ($stages as $stage) {
            $sort = (int) $stage->sort_order;
            $outer->selectRaw("SUM(CASE WHEN COALESCE(r.h_sort, -1) >= {$sort} OR COALESCE(r.c_sort, -1) >= {$sort} THEN 1 ELSE 0 END) AS s{$stage->id}");
        }
        $row = $outer->first();

        $start = null;
        $previous = null;
        $out = [];
        foreach ($stages as $stage) {
            $reached = Metric::int($row->{"s{$stage->id}"} ?? 0);
            $start ??= $reached;
            $out[] = [
                'status_id' => $stage->id,
                'name' => $stage->name,
                'is_won' => (bool) $stage->is_won,
                'reached' => $reached,
                'rate_from_start' => Metric::rate($reached, $start),
                'rate_from_previous' => $previous === null ? null : Metric::rate($reached, $previous),
            ];
            $previous = $reached;
        }

        return $out;
    }

    /** Cohort leads that reached the qualified stage (setting report.qualified_status). */
    public function qualified(array $funnel): ?int
    {
        $status = $this->lookups->qualifiedStatus();
        if (! $status) {
            return null;
        }

        foreach ($funnel as $stage) {
            if ((int) $this->lookups->statuses()->get($stage['status_id'])?->sort_order >= (int) $status->sort_order) {
                return $stage['reached'];
            }
        }

        return 0;
    }

    /**
     * Time spent in each status, for stage exits inside the period. The time
     * in a stage runs from entering it until the next status change of that
     * lead. Leads still in a stage are not included (see time-in-current-status).
     *
     * @return array<int, array{status_id: int, name: string, exits: int, avg: ?float, p50: ?float, p75: ?float, p90: ?float}>
     */
    public function stageDurations(ReportQueries $q): array
    {
        $diff = ReportSql::diffSeconds('t.changed_at', 't.next_at');
        $intervals = DB::query()->fromSub(
            $q->statusChanges()->toBase()->select('lead_status_changes.to_status_id', 'lead_status_changes.changed_at')
                ->selectRaw('LEAD(lead_status_changes.changed_at) OVER (PARTITION BY lead_status_changes.lead_id ORDER BY lead_status_changes.changed_at, lead_status_changes.id) AS next_at'),
            't'
        )->whereNotNull('t.next_at')->whereBetween('t.next_at', [$q->filters->from, $q->filters->to]);

        $rows = (clone $intervals)->selectRaw("t.to_status_id AS s, COUNT(*) AS c, AVG({$diff}) AS a")
            ->groupBy('t.to_status_id')->get()->keyBy('s');

        $out = [];
        foreach ($this->lookups->statuses() as $status) {
            /** @var LeadStatus $status */
            if ($status->is_won || $status->is_lost || ! isset($rows[$status->id])) {
                continue;
            }
            $p = ReportSql::percentiles((clone $intervals)->where('t.to_status_id', $status->id), $diff, [50, 75, 90]);
            $out[] = [
                'status_id' => $status->id,
                'name' => $status->name,
                'exits' => (int) $rows[$status->id]->c,
                'avg' => $rows[$status->id]->a !== null ? round((float) $rows[$status->id]->a) : null,
                'p50' => $p[50], 'p75' => $p[75], 'p90' => $p[90],
            ];
        }

        return $out;
    }

    /**
     * Created → won (or lost) for leads whose win / loss happened in the
     * period (first such transition in the period per lead).
     *
     * @return array{count: int, avg: ?float, p50: ?float, p75: ?float, p90: ?float}
     */
    public function timeTo(ReportQueries $q, string $kind): array
    {
        $ids = $kind === 'won' ? $this->lookups->wonIds() : $this->lookups->lostIds();
        $firstInPeriod = $q->statusChanges()->toBase()
            ->whereIn('lead_status_changes.to_status_id', $ids)
            ->whereBetween('lead_status_changes.changed_at', [$q->filters->from, $q->filters->to])
            ->groupBy('lead_status_changes.lead_id')
            ->selectRaw('lead_status_changes.lead_id, MIN(lead_status_changes.changed_at) AS at');

        $diff = ReportSql::diffSeconds('l.created_at', 'x.at');
        $base = DB::query()->fromSub($firstInPeriod, 'x')->join('leads as l', 'l.id', '=', 'x.lead_id');
        $row = (clone $base)->selectRaw("COUNT(*) AS c, AVG({$diff}) AS a")->first();
        $p = ReportSql::percentiles($base, $diff, [50, 75, 90]);

        return [
            'count' => Metric::int($row->c ?? 0),
            'avg' => $row->a !== null ? round((float) $row->a) : null,
            'p50' => $p[50], 'p75' => $p[75], 'p90' => $p[90],
        ];
    }
}
