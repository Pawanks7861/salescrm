<?php

namespace App\Services\Followups;

use App\Enums\FollowupStatus;
use App\Models\Followup;
use App\Models\User;
use App\Support\CrmTime;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reusable, visibility-scoped follow-up aggregates for dashboards and (later)
 * reports. One query per call; never loads follow-up rows.
 */
class FollowupMetrics
{
    /**
     * Counts for the viewer's scope (own / company per FollowupVisibility).
     *
     * @return array{overdue: int, today: int, upcoming: int, completed_today: int}
     */
    public function summary(User $viewer, bool $onlyMine = false): array
    {
        $now = now()->toDateTimeString();
        $start = CrmTime::startOfToday()->toDateTimeString();
        $end = CrmTime::endOfToday()->toDateTimeString();
        $pending = FollowupStatus::Pending->value;

        $row = $this->scoped($viewer, $onlyMine)
            ->selectRaw('SUM(CASE WHEN followups.status = ? AND scheduled_at < ? THEN 1 ELSE 0 END) AS overdue', [$pending, $now])
            ->selectRaw('SUM(CASE WHEN followups.status = ? AND scheduled_at >= ? AND scheduled_at <= ? THEN 1 ELSE 0 END) AS today', [$pending, $now, $end])
            ->selectRaw('SUM(CASE WHEN followups.status = ? AND scheduled_at > ? THEN 1 ELSE 0 END) AS upcoming', [$pending, $end])
            ->selectRaw('SUM(CASE WHEN followups.status = ? AND completed_at >= ? AND completed_at <= ? THEN 1 ELSE 0 END) AS completed_today', [FollowupStatus::Completed->value, $start, $end])
            ->toBase()
            ->first();

        return [
            'overdue' => (int) ($row->overdue ?? 0),
            'today' => (int) ($row->today ?? 0),
            'upcoming' => (int) ($row->upcoming ?? 0),
            'completed_today' => (int) ($row->completed_today ?? 0),
        ];
    }

    /**
     * Per-assignee discipline figures for a period (scheduled_at within range):
     * created, completed, currently overdue, completion rate and average
     * completion delay in minutes (completed_at − scheduled_at).
     *
     * @return array<int, array{assigned_to: int|null, total: int, completed: int, overdue: int, completion_rate: float, avg_delay_minutes: float|null}>
     */
    public function byAssignee(User $viewer, CarbonInterface $from, CarbonInterface $to): array
    {
        $delay = DB::connection()->getDriverName() === 'sqlite'
            ? '(julianday(completed_at) - julianday(scheduled_at)) * 1440'
            : 'TIMESTAMPDIFF(MINUTE, scheduled_at, completed_at)';

        return $this->scoped($viewer)
            ->whereBetween('scheduled_at', [$from, $to])
            ->groupBy('followups.assigned_to')
            ->selectRaw('followups.assigned_to, COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN followups.status = ? THEN 1 ELSE 0 END) AS completed', [FollowupStatus::Completed->value])
            ->selectRaw('SUM(CASE WHEN followups.status = ? AND scheduled_at < ? THEN 1 ELSE 0 END) AS overdue', [FollowupStatus::Pending->value, now()->toDateTimeString()])
            ->selectRaw("AVG(CASE WHEN followups.status = ? THEN {$delay} END) AS avg_delay", [FollowupStatus::Completed->value])
            ->toBase()
            ->get()
            ->map(fn ($r) => [
                'assigned_to' => $r->assigned_to !== null ? (int) $r->assigned_to : null,
                'total' => (int) $r->total,
                'completed' => (int) $r->completed,
                'overdue' => (int) $r->overdue,
                'completion_rate' => $r->total > 0 ? round($r->completed / $r->total * 100, 1) : 0.0,
                'avg_delay_minutes' => $r->avg_delay !== null ? round((float) $r->avg_delay, 1) : null,
            ])
            ->all();
    }

    private function scoped(User $viewer, bool $onlyMine = false): Builder
    {
        return Followup::query()->visibleTo($viewer)
            ->when($onlyMine, fn (Builder $q) => $q->where('followups.assigned_to', $viewer->id));
    }
}
