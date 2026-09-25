<?php

namespace App\Services\Reports\Metrics;

use App\Services\Reports\Metric;
use App\Services\Reports\ReportLookups;
use App\Services\Reports\ReportQueries;
use App\Services\Reports\ReportSql;
use App\Support\CrmTime;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Lead volume and outcomes.
 *
 * Two deliberately separate views:
 *  - Acquisition cohort: leads CREATED in the period and what they are now
 *    (e.g. "of 120 leads created in March, 14 are won today").
 *  - Period outcomes: leads that MOVED to won / lost during the period,
 *    regardless of when they were created, read from lead_status_changes.
 */
class LeadMetrics
{
    public function __construct(private readonly ReportLookups $lookups) {}

    /** One-query cohort summary. */
    public function cohortSummary(ReportQueries $q): array
    {
        $row = $this->withCurrentStatus($q->cohort())
            ->selectRaw('COUNT(*) AS leads')
            ->selectRaw('SUM(CASE WHEN leads.is_duplicate = 1 THEN 1 ELSE 0 END) AS duplicates')
            ->selectRaw('SUM(CASE WHEN leads.deleted_at IS NOT NULL THEN 1 ELSE 0 END) AS archived')
            ->selectRaw('SUM(CASE WHEN leads.assigned_to IS NULL THEN 1 ELSE 0 END) AS unassigned')
            ->selectRaw('SUM(CASE WHEN cs.is_won = 1 THEN 1 ELSE 0 END) AS won_now')
            ->selectRaw('SUM(CASE WHEN cs.is_lost = 1 THEN 1 ELSE 0 END) AS lost_now')
            ->toBase()->first();

        $leads = Metric::int($row->leads ?? 0);

        return [
            'leads' => $leads,
            'duplicates' => Metric::int($row->duplicates ?? 0),
            'archived' => Metric::int($row->archived ?? 0),
            'unassigned' => Metric::int($row->unassigned ?? 0),
            'won_now' => Metric::int($row->won_now ?? 0),
            'lost_now' => Metric::int($row->lost_now ?? 0),
            'open_now' => $leads - Metric::int($row->won_now ?? 0) - Metric::int($row->lost_now ?? 0),
            'cohort_win_rate' => Metric::rate($row->won_now ?? 0, $leads),
        ];
    }

    /**
     * Cohort grouped by a lead column / expression.
     *
     * @return Collection<int, object{k: mixed, leads: int, won: int, lost: int, open: int, won_value: float, open_value: float}>
     */
    public function breakdown(ReportQueries $q, string $groupExpr, ?int $limit = null): Collection
    {
        return $this->withCurrentStatus($q->cohort())
            ->selectRaw("{$groupExpr} AS k")
            ->selectRaw('COUNT(*) AS leads')
            ->selectRaw('SUM(CASE WHEN cs.is_won = 1 THEN 1 ELSE 0 END) AS won')
            ->selectRaw('SUM(CASE WHEN cs.is_lost = 1 THEN 1 ELSE 0 END) AS lost')
            ->selectRaw('SUM(CASE WHEN cs.is_won = 1 THEN COALESCE(leads.estimated_value, 0) ELSE 0 END) AS won_value')
            ->selectRaw('SUM(CASE WHEN cs.is_won = 0 AND cs.is_lost = 0 THEN COALESCE(leads.estimated_value, 0) ELSE 0 END) AS open_value')
            ->groupByRaw($groupExpr)
            ->orderByDesc('leads')
            ->when($limit, fn ($b) => $b->limit($limit))
            ->toBase()->get()
            ->map(function ($r) {
                $r->leads = (int) $r->leads;
                $r->won = (int) $r->won;
                $r->lost = (int) $r->lost;
                $r->open = $r->leads - $r->won - $r->lost;
                $r->won_value = Metric::money($r->won_value);
                $r->open_value = Metric::money($r->open_value);

                return $r;
            });
    }

    /** @return array<string, int> bucket (Y-m-d) → leads created */
    public function createdTrend(ReportQueries $q): array
    {
        return $this->trend($q->cohort(), 'leads.created_at', $q);
    }

    /** Subquery of lead ids that moved to a won (or lost) status inside the period. */
    public function transitionedIds(ReportQueries $q, string $kind): Builder
    {
        $ids = $kind === 'won' ? $this->lookups->wonIds() : $this->lookups->lostIds();

        return $q->statusChanges()
            ->whereIn('lead_status_changes.to_status_id', $ids)
            ->whereBetween('lead_status_changes.changed_at', [$q->filters->from, $q->filters->to])
            ->select('lead_status_changes.lead_id');
    }

    /** Leads won / lost during the period (distinct leads) and their current estimated value. */
    public function periodOutcome(ReportQueries $q, string $kind): array
    {
        $row = $q->leads()->whereIn('leads.id', $this->transitionedIds($q, $kind))
            ->selectRaw('COUNT(*) AS c, SUM(COALESCE(leads.estimated_value, 0)) AS v')
            ->toBase()->first();

        return ['count' => Metric::int($row->c ?? 0), 'value' => Metric::money($row->v ?? 0)];
    }

    /** @return Collection<int|string, object{k: mixed, c: int, v: float}> keyed by group */
    public function periodOutcomeBy(ReportQueries $q, string $kind, string $groupColumn): Collection
    {
        return $q->leads()->whereIn('leads.id', $this->transitionedIds($q, $kind))
            ->selectRaw("{$groupColumn} AS k, COUNT(*) AS c, SUM(COALESCE(leads.estimated_value, 0)) AS v")
            ->groupBy($groupColumn)
            ->toBase()->get()
            ->keyBy(fn ($r) => (string) ($r->k ?? ''));
    }

    /** @return array<string, int> */
    public function periodOutcomeTrend(ReportQueries $q, string $kind): array
    {
        $ids = $kind === 'won' ? $this->lookups->wonIds() : $this->lookups->lostIds();
        $query = $q->statusChanges()
            ->whereIn('lead_status_changes.to_status_id', $ids)
            ->whereBetween('lead_status_changes.changed_at', [$q->filters->from, $q->filters->to]);

        return $this->trend($query, 'lead_status_changes.changed_at', $q, 'COUNT(DISTINCT lead_status_changes.lead_id)');
    }

    /** Win rate for the period: won ÷ (won + lost), both from transitions in the period. */
    public function periodWinRate(array $won, array $lost): ?float
    {
        return Metric::rate($won['count'], $won['count'] + $lost['count']);
    }

    /**
     * Generic trend helper: counts grouped by CRM-local bucket, zero-filled.
     *
     * @return array<string, int>
     */
    public function trend(Builder $query, string $column, ReportQueries $q, string $aggregate = 'COUNT(*)'): array
    {
        $offset = ReportSql::offsetSeconds($q->filters->to);
        $bucket = ReportSql::bucket($column, $q->filters->granularity(), $offset);

        $rows = (clone $query)->toBase()
            ->selectRaw("{$bucket} AS b, {$aggregate} AS c")
            ->groupByRaw($bucket)
            ->pluck('c', 'b');

        return self::fill($q, $rows->map(fn ($v) => (int) $v)->all());
    }

    /**
     * Zero-filled bucket keys for the whole period.
     *
     * @return array<string, int|float>
     */
    public static function fill(ReportQueries $q, array $values): array
    {
        $tz = CrmTime::tz();
        $cursor = $q->filters->from->setTimezone($tz)->startOfDay();
        $end = $q->filters->to->setTimezone($tz);
        $granularity = $q->filters->granularity();

        $cursor = match ($granularity) {
            'week' => $cursor->startOfWeek(CarbonInterface::MONDAY),
            'month' => $cursor->startOfMonth(),
            default => $cursor,
        };

        $out = [];
        while ($cursor->lte($end)) {
            $key = $cursor->format('Y-m-d');
            $out[$key] = $values[$key] ?? 0;
            $cursor = match ($granularity) {
                'week' => $cursor->addWeek(),
                'month' => $cursor->addMonthNoOverflow(),
                default => $cursor->addDay(),
            };
        }

        return $out;
    }

    public function withCurrentStatus(Builder $leads): Builder
    {
        return $leads->join('lead_statuses as cs', 'cs.id', '=', 'leads.status_id');
    }
}
