<?php

namespace App\Services\Reports\Metrics;

use App\Enums\MeetingStatus;
use App\Services\Reports\Metric;
use App\Services\Reports\ReportLookups;
use App\Services\Reports\ReportQueries;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Meeting analytics, period = meetings.start_at. A meeting that was
 * rescheduled is replaced by a new meeting; the original (status
 * `rescheduled`) is excluded from every count except "Rescheduled", so a
 * reschedule never inflates scheduled or upcoming meetings.
 *
 *  Completion rate = completed ÷ (completed + cancelled + no-show)
 *  No-show rate    = no-show ÷ (completed + no-show)
 */
class MeetingMetrics
{
    public function __construct(private readonly ReportLookups $lookups) {}

    public function inPeriod(ReportQueries $q): Builder
    {
        return $q->meetings()
            ->where('meetings.status', '!=', MeetingStatus::Rescheduled->value)
            ->whereBetween('meetings.start_at', [$q->filters->from, $q->filters->to]);
    }

    private function aggregates(Builder $query): Builder
    {
        $open = "'".implode("','", MeetingStatus::openValues())."'";
        $now = "'".now()->utc()->format('Y-m-d H:i:s')."'";

        return $query
            ->selectRaw('COUNT(*) AS scheduled')
            ->selectRaw("SUM(CASE WHEN meetings.status = 'completed' THEN 1 ELSE 0 END) AS completed")
            ->selectRaw("SUM(CASE WHEN meetings.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled")
            ->selectRaw("SUM(CASE WHEN meetings.status = 'no_show' THEN 1 ELSE 0 END) AS no_show")
            ->selectRaw("SUM(CASE WHEN meetings.status IN ({$open}) AND meetings.end_at < {$now} THEN 1 ELSE 0 END) AS awaiting_update")
            ->selectRaw("SUM(CASE WHEN meetings.status IN ({$open}) AND meetings.end_at >= {$now} THEN 1 ELSE 0 END) AS still_upcoming");
    }

    public function summary(ReportQueries $q): array
    {
        return [
            ...$this->shape($this->aggregates($this->inPeriod($q))->toBase()->first()),
            'rescheduled' => $q->meetings()->where('meetings.status', MeetingStatus::Rescheduled->value)
                ->whereBetween('meetings.start_at', [$q->filters->from, $q->filters->to])->count(),
            'upcoming_now' => $this->upcomingNow($q),
        ];
    }

    /** Snapshot: scheduled / confirmed meetings starting from now on. */
    public function upcomingNow(ReportQueries $q): int
    {
        return $q->meetings()->whereIn('meetings.status', [MeetingStatus::Scheduled->value, MeetingStatus::Confirmed->value])
            ->where('meetings.start_at', '>=', now())->count();
    }

    /** @return Collection<string, array> */
    public function by(ReportQueries $q, string $column): Collection
    {
        return $this->aggregates($this->inPeriod($q))->selectRaw("{$column} AS k")->groupBy($column)->toBase()->get()
            ->mapWithKeys(fn ($r) => [(string) ($r->k ?? '') => $this->shape($r)]);
    }

    /** @return array<string, int> */
    public function outcomes(ReportQueries $q): array
    {
        return $this->inPeriod($q)->where('meetings.status', 'completed')->toBase()
            ->selectRaw('meetings.outcome AS o, COUNT(*) AS c')->groupBy('meetings.outcome')->orderByDesc('c')
            ->pluck('c', 'o')->map(fn ($v) => (int) $v)->all();
    }

    /** Distinct leads with a completed meeting in the period that are won today (descriptive). */
    public function leadsMetNowWon(ReportQueries $q): array
    {
        $met = $this->inPeriod($q)->where('meetings.status', 'completed')->whereNotNull('meetings.lead_id')->select('meetings.lead_id');
        $leads = $q->leads()->whereIn('leads.id', $met);

        return [
            'leads' => (clone $leads)->count(),
            'won' => $leads->whereIn('leads.status_id', $this->lookups->wonIds())->count(),
        ];
    }

    private function shape(?object $r): array
    {
        $i = fn (string $k) => Metric::int($r->{$k} ?? 0);
        $closed = $i('completed') + $i('cancelled') + $i('no_show');

        return [
            'scheduled' => $i('scheduled'),
            'completed' => $i('completed'),
            'cancelled' => $i('cancelled'),
            'no_show' => $i('no_show'),
            'awaiting_update' => $i('awaiting_update'),
            'still_upcoming' => $i('still_upcoming'),
            'completion_rate' => Metric::rate($i('completed'), $closed),
            'no_show_rate' => Metric::rate($i('no_show'), $i('completed') + $i('no_show')),
        ];
    }
}
