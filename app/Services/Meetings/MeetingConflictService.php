<?php

namespace App\Services\Meetings;

use App\Enums\AttendanceStatus;
use App\Enums\MeetingParticipantType;
use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\User;
use App\Services\SettingService;
use App\Support\CrmTime;
use App\Support\Permissions;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Calendar conflict detection for hosts and internal participants.
 *
 * Two meetings overlap when   existing.start_at < new.end_at
 *                         AND existing.end_at   > new.start_at
 * (touching edges, e.g. 10–11 and 11–12, do not overlap). Cancelled and
 * rescheduled meetings never block; declined participants are not busy.
 * All instants are UTC, so comparisons are timezone-safe.
 *
 * Messages only reveal a conflicting meeting's time and title when the actor
 * can view that meeting; otherwise the participant is simply "unavailable".
 */
class MeetingConflictService
{
    public const UNAVAILABLE = 'is unavailable during the selected time.';

    public function __construct(
        private readonly MeetingVisibility $visibility,
        private readonly SettingService $settings,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('meeting.conflict_checking', true);
    }

    /**
     * @param  array<int>  $userIds
     * @param  array<int>  $excludeMeetingIds
     * @return Collection<int, array{user_id: int, meeting: Meeting}>
     */
    public function find(CarbonInterface $start, CarbonInterface $end, array $userIds, array $excludeMeetingIds = []): Collection
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if ($userIds === []) {
            return collect();
        }

        $busyParticipant = fn (Builder $p) => $p
            ->where('participant_type', MeetingParticipantType::User->value)
            ->whereIn('user_id', $userIds)
            ->where('attendance_status', '!=', AttendanceStatus::Declined->value);

        $meetings = Meeting::query()
            ->whereNotIn('status', MeetingStatus::nonBlocking())
            ->where('start_at', '<', $end)
            ->where('end_at', '>', $start)
            ->when($excludeMeetingIds !== [], fn (Builder $q) => $q->whereNotIn('id', $excludeMeetingIds))
            ->where(fn (Builder $q) => $q->whereIn('host_user_id', $userIds)->orWhereHas('participants', $busyParticipant))
            ->with(['participants' => fn ($q) => $q->where('participant_type', MeetingParticipantType::User->value), 'lead'])
            ->orderBy('start_at')
            ->get();

        $conflicts = collect();
        foreach ($userIds as $userId) {
            $meeting = $meetings->first(fn (Meeting $m) => (int) $m->host_user_id === $userId
                || $m->participants->contains(fn (MeetingParticipant $p) => (int) $p->user_id === $userId && $p->attendance_status !== AttendanceStatus::Declined));

            if ($meeting) {
                $conflicts->push(['user_id' => $userId, 'meeting' => $meeting]);
            }
        }

        return $conflicts;
    }

    /**
     * Throws a validation error listing conflicts unless there are none, checking
     * is disabled, or the actor holds meeting.override_conflict and confirmed the
     * override. Returns the conflicts that were overridden (for auditing).
     *
     * @param  array<int>  $userIds
     * @param  array<int>  $excludeMeetingIds
     * @return Collection<int, array{user_id: int, meeting: Meeting}>
     *
     * @throws ValidationException
     */
    public function check(CarbonInterface $start, CarbonInterface $end, array $userIds, User $actor, bool $override, array $excludeMeetingIds = []): Collection
    {
        if (! $this->enabled()) {
            return collect();
        }

        $conflicts = $this->find($start, $end, $userIds, $excludeMeetingIds);
        if ($conflicts->isEmpty()) {
            return $conflicts;
        }

        if ($override && $this->canOverride($actor)) {
            return $conflicts;
        }

        $names = User::query()->withTrashed()->whereIn('id', $conflicts->pluck('user_id'))->pluck('name', 'id');
        $messages = $conflicts->values()->mapWithKeys(fn (array $c, int $i) => [
            "conflicts.{$i}" => $this->message($names[$c['user_id']] ?? 'This participant', $c['meeting'], $actor),
        ])->all();

        if ($this->canOverride($actor)) {
            $messages['conflict_override'] = 'Confirm the override to schedule despite these conflicts.';
        }

        throw ValidationException::withMessages($messages);
    }

    public function canOverride(User $actor): bool
    {
        return $actor->hasPermission(Permissions::MEETING_OVERRIDE_CONFLICT);
    }

    public function message(string $name, Meeting $meeting, User $actor): string
    {
        if (! $this->visibility->canView($actor, $meeting)) {
            return "{$name} ".self::UNAVAILABLE;
        }

        $sameDay = CrmTime::format($meeting->start_at, 'Y-m-d') === CrmTime::format($meeting->end_at, 'Y-m-d');
        $from = CrmTime::format($meeting->start_at, 'g:i A');
        $to = CrmTime::format($meeting->end_at, $sameDay ? 'g:i A' : CrmTime::DISPLAY_FORMAT);
        $date = CrmTime::format($meeting->start_at, 'M j');

        return "{$name} already has another meeting from {$from} to {$to} on {$date} ({$meeting->meeting_number}: {$meeting->title}).";
    }
}
