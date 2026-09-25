<?php

namespace App\Services\Reports\Metrics;

use App\Services\Reports\Metric;
use App\Services\Reports\ReportQueries;
use App\Services\Reports\ReportSql;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Assignment history from lead_assignments (period = created_at), limited to
 * leads the viewer can currently see.
 *
 *  Reassignment = a row moving a lead from one user to another user
 *  Unassignment = a row with no new user (owner cleared; legacy rows may be team queues)
 */
class AssignmentMetrics
{
    public function inPeriod(ReportQueries $q): Builder
    {
        return $q->assignments()->whereBetween('lead_assignments.created_at', [$q->filters->from, $q->filters->to]);
    }

    public function summary(ReportQueries $q): array
    {
        $row = $this->inPeriod($q)->toBase()
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('COUNT(DISTINCT lead_assignments.lead_id) AS leads')
            ->selectRaw('SUM(CASE WHEN lead_assignments.from_user_id IS NULL AND lead_assignments.to_user_id IS NOT NULL THEN 1 ELSE 0 END) AS first_assignments')
            ->selectRaw('SUM(CASE WHEN lead_assignments.from_user_id IS NOT NULL AND lead_assignments.to_user_id IS NOT NULL AND lead_assignments.from_user_id <> lead_assignments.to_user_id THEN 1 ELSE 0 END) AS reassignments')
            ->selectRaw('SUM(CASE WHEN lead_assignments.to_user_id IS NULL THEN 1 ELSE 0 END) AS unassignments')
            ->first();

        return [
            'total' => Metric::int($row->total ?? 0),
            'leads' => Metric::int($row->leads ?? 0),
            'first_assignments' => Metric::int($row->first_assignments ?? 0),
            'reassignments' => Metric::int($row->reassignments ?? 0),
            'unassignments' => Metric::int($row->unassignments ?? 0),
            'unassigned_open_now' => $q->openLeads()->whereNull('leads.assigned_to')->count(),
        ];
    }

    /** @return array<string, int> */
    public function byType(ReportQueries $q): array
    {
        return $this->inPeriod($q)->toBase()->selectRaw('lead_assignments.assignment_type AS t, COUNT(*) AS c')
            ->groupBy('lead_assignments.assignment_type')->orderByDesc('c')->pluck('c', 't')->map(fn ($v) => (int) $v)->all();
    }

    /** @return array{received: Collection, removed: Collection, by: Collection} */
    public function byUser(ReportQueries $q): array
    {
        $count = fn (string $col, ?callable $extra = null) => $this->inPeriod($q)->toBase()
            ->when($extra, $extra)
            ->whereNotNull($col)->selectRaw("{$col} AS k, COUNT(*) AS c")->groupBy($col)->pluck('c', 'k');

        return [
            'received' => $count('lead_assignments.to_user_id'),
            'removed' => $count('lead_assignments.from_user_id', fn ($b) => $b->whereColumn('lead_assignments.from_user_id', '!=', DB::raw('COALESCE(lead_assignments.to_user_id, 0)'))),
            'by' => $count('lead_assignments.assigned_by'),
        ];
    }

    /** Created → first assignment to a user, for cohort leads. */
    public function timeToAssignment(ReportQueries $q): array
    {
        $first = '(SELECT MIN(fa.created_at) FROM lead_assignments fa WHERE fa.lead_id = leads.id AND fa.to_user_id IS NOT NULL)';
        $diff = ReportSql::diffSeconds('leads.created_at', $first);
        $sub = $q->cohort()->toBase()->selectRaw("CASE WHEN {$first} IS NULL THEN NULL WHEN {$diff} < 0 THEN 0 ELSE {$diff} END AS s");

        $row = DB::query()->fromSub($sub, 'x')->selectRaw('COUNT(x.s) AS c, AVG(x.s) AS a')->first();
        $p = ReportSql::percentiles(DB::query()->fromSub($sub, 'x'), 'x.s', [50]);

        return ['count' => Metric::int($row->c ?? 0), 'avg' => $row->a !== null ? round((float) $row->a) : null, 'median' => $p[50]];
    }
}
