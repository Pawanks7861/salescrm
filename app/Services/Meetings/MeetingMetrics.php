<?php

namespace App\Services\Meetings;

use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Models\User;
use App\Support\CrmTime;
use Illuminate\Database\Eloquent\Builder;

/**
 * Visibility-scoped meeting aggregates for dashboards (and later reports).
 * "Next meeting" is computed from the indexed start_at query, never stored.
 */
class MeetingMetrics
{
    /** @return array{today: int, upcoming: int, completed_today: int} */
    public function summary(User $viewer, bool $onlyMine = false): array
    {
        $now = now()->toDateTimeString();
        $start = CrmTime::startOfToday()->toDateTimeString();
        $end = CrmTime::endOfToday()->toDateTimeString();
        $open = MeetingStatus::openValues();
        $in = implode(',', array_fill(0, count($open), '?'));

        $row = $this->scoped($viewer, $onlyMine)
            ->selectRaw("SUM(CASE WHEN meetings.status IN ({$in}) AND start_at >= ? AND start_at <= ? THEN 1 ELSE 0 END) AS today", [...$open, $start, $end])
            ->selectRaw("SUM(CASE WHEN meetings.status IN ({$in}) AND end_at >= ? THEN 1 ELSE 0 END) AS upcoming", [...$open, $now])
            ->selectRaw('SUM(CASE WHEN meetings.status = ? AND completed_at >= ? AND completed_at <= ? THEN 1 ELSE 0 END) AS completed_today', [MeetingStatus::Completed->value, $start, $end])
            ->toBase()
            ->first();

        return [
            'today' => (int) ($row->today ?? 0),
            'upcoming' => (int) ($row->upcoming ?? 0),
            'completed_today' => (int) ($row->completed_today ?? 0),
        ];
    }

    /** Open meetings starting today (CRM timezone), soonest first. */
    public function today(User $viewer, bool $onlyMine, int $limit = 8): Builder
    {
        return $this->scoped($viewer, $onlyMine)
            ->whereIn('meetings.status', MeetingStatus::openValues())
            ->whereBetween('start_at', [CrmTime::startOfToday(), CrmTime::endOfToday()])
            ->orderBy('start_at')
            ->orderBy('meetings.id')
            ->limit($limit);
    }

    /** The next open meeting that has not ended yet. */
    public function next(User $viewer, bool $onlyMine): ?Meeting
    {
        return $this->scoped($viewer, $onlyMine)
            ->whereIn('meetings.status', MeetingStatus::openValues())
            ->where('end_at', '>=', now())
            ->orderBy('start_at')
            ->orderBy('meetings.id')
            ->first();
    }

    private function scoped(User $viewer, bool $onlyMine): Builder
    {
        return Meeting::query()->visibleTo($viewer)
            ->when($onlyMine, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('meetings.host_user_id', $viewer->id)
                ->orWhereHas('participants', fn (Builder $p) => $p->where('user_id', $viewer->id))));
    }
}
