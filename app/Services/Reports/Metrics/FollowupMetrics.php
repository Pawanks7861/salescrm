<?php

namespace App\Services\Reports\Metrics;

use App\Services\Reports\Metric;
use App\Services\Reports\ReportQueries;
use App\Services\Reports\ReportSql;
use App\Services\SettingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Follow-up analytics. Overdue is never stored: pending AND scheduled_at < now.
 *
 *  Due in period   = scheduled_at between the period start and min(period end,
 *                    now), excluding follow-ups replaced by a reschedule (the
 *                    replacement is counted instead, so nothing is doubled)
 *  Completion rate = completed ÷ due
 *  On time         = completed no later than scheduled_at + the overdue alert
 *                    grace (setting followup.overdue_alert_after_minutes)
 */
class FollowupMetrics
{
    public function __construct(private readonly SettingService $settings) {}

    public function graceMinutes(): int
    {
        return max(0, (int) $this->settings->get('followup.overdue_alert_after_minutes', 60));
    }

    public function due(ReportQueries $q): Builder
    {
        $end = $q->filters->to->min(now()->toImmutable());

        return $q->followups()
            ->where('followups.status', '!=', 'rescheduled')
            ->whereBetween('followups.scheduled_at', [$q->filters->from, $end]);
    }

    private function aggregates(Builder $query): Builder
    {
        $grace = $this->graceMinutes() * 60;
        $late = ReportSql::diffSeconds('followups.scheduled_at', 'followups.completed_at');
        $now = ReportSql::at(now()->toImmutable());

        return $query
            ->selectRaw('COUNT(*) AS due')
            ->selectRaw("SUM(CASE WHEN followups.status = 'completed' THEN 1 ELSE 0 END) AS completed")
            ->selectRaw("SUM(CASE WHEN followups.status = 'completed' AND {$late} <= {$grace} THEN 1 ELSE 0 END) AS on_time")
            ->selectRaw("SUM(CASE WHEN followups.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled")
            ->selectRaw("SUM(CASE WHEN followups.status = 'pending' AND followups.scheduled_at < {$now} THEN 1 ELSE 0 END) AS still_overdue");
    }

    public function summary(ReportQueries $q): array
    {
        $row = $this->aggregates($this->due($q))->toBase()->first();

        return [
            ...$this->shape($row),
            'completed_in_period' => $q->followups()->where('followups.status', 'completed')
                ->whereBetween('followups.completed_at', [$q->filters->from, $q->filters->to])->count(),
            'rescheduled' => $q->followups()->where('followups.status', 'rescheduled')
                ->whereBetween('followups.scheduled_at', [$q->filters->from, $q->filters->to])->count(),
            'overdue_now' => $this->overdueNow($q),
        ];
    }

    /** Snapshot: pending follow-ups past their time right now (not period bound). */
    public function overdueNow(ReportQueries $q): int
    {
        return $q->followups()->where('followups.status', 'pending')->where('followups.scheduled_at', '<', now())->count();
    }

    /** @return Collection<string, array> */
    public function by(ReportQueries $q, string $column): Collection
    {
        $overdue = $q->followups()->where('followups.status', 'pending')->where('followups.scheduled_at', '<', now())
            ->toBase()->selectRaw("{$column} AS k, COUNT(*) AS c")->groupBy($column)->pluck('c', 'k');

        $completed = $q->followups()->where('followups.status', 'completed')
            ->whereBetween('followups.completed_at', [$q->filters->from, $q->filters->to])
            ->toBase()->selectRaw("{$column} AS k, COUNT(*) AS c")->groupBy($column)->pluck('c', 'k');

        $rows = $this->aggregates($this->due($q))->selectRaw("{$column} AS k")->groupBy($column)->toBase()->get()
            ->mapWithKeys(fn ($r) => [(string) ($r->k ?? '') => $this->shape($r)]);

        foreach ($overdue->keys()->merge($completed->keys())->unique() as $k) {
            $rows[(string) $k] ??= $this->shape(null);
        }

        return $rows->map(fn ($r, $k) => [...$r, 'overdue_now' => (int) ($overdue[$k] ?? 0), 'completed_in_period' => (int) ($completed[$k] ?? 0)]);
    }

    /** @return array<string, int> outcome → count, follow-ups completed in the period */
    public function outcomes(ReportQueries $q): array
    {
        return $q->followups()->where('followups.status', 'completed')
            ->whereBetween('followups.completed_at', [$q->filters->from, $q->filters->to])
            ->toBase()->selectRaw('followups.outcome AS o, COUNT(*) AS c')->groupBy('followups.outcome')->orderByDesc('c')
            ->pluck('c', 'o')->map(fn ($v) => (int) $v)->all();
    }

    /** @return Collection<int, object{k: int, due: int, completed: int}> */
    public function byType(ReportQueries $q): Collection
    {
        return $this->aggregates($this->due($q))->selectRaw('followups.followup_type_id AS k')
            ->groupBy('followups.followup_type_id')->toBase()->get();
    }

    private function shape(?object $r): array
    {
        $i = fn (string $k) => Metric::int($r->{$k} ?? 0);

        return [
            'due' => $i('due'),
            'completed' => $i('completed'),
            'on_time' => $i('on_time'),
            'cancelled' => $i('cancelled'),
            'still_overdue' => $i('still_overdue'),
            'completion_rate' => Metric::rate($i('completed'), $i('due')),
            'on_time_rate' => Metric::rate($i('on_time'), $i('completed')),
        ];
    }
}
