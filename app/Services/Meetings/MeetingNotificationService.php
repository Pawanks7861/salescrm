<?php

namespace App\Services\Meetings;

use App\Models\Meeting;
use App\Models\User;
use App\Notifications\Meetings\MeetingActivityNotification;
use Illuminate\Support\Facades\DB;

/**
 * User-triggered meeting notifications (in-app). Dispatched after the
 * surrounding transaction commits, never to the actor, only to active users
 * who can still see the meeting. External participants are not contacted.
 */
class MeetingNotificationService
{
    public function __construct(private readonly MeetingVisibility $visibility) {}

    public function assigned(Meeting $meeting, User $actor): void
    {
        $this->send($meeting, [(int) $meeting->host_user_id], MeetingActivityNotification::ASSIGNED, $actor);
    }

    /** @param  array<int>  $userIds newly invited internal participants */
    public function invited(Meeting $meeting, array $userIds, User $actor): void
    {
        $this->send($meeting, array_diff($userIds, [(int) $meeting->host_user_id]), MeetingActivityNotification::SCHEDULED, $actor);
    }

    public function rescheduled(Meeting $meeting, User $actor): void
    {
        $this->send($meeting, $meeting->attendeeUserIds(), MeetingActivityNotification::RESCHEDULED, $actor);
    }

    public function cancelled(Meeting $meeting, User $actor): void
    {
        $this->send($meeting, $meeting->attendeeUserIds(), MeetingActivityNotification::CANCELLED, $actor);
    }

    public function completed(Meeting $meeting, User $actor): void
    {
        $this->send($meeting, $meeting->attendeeUserIds(), MeetingActivityNotification::COMPLETED, $actor);
    }

    /** @param  array<int>  $userIds */
    private function send(Meeting $meeting, array $userIds, string $kind, User $actor): void
    {
        $ids = array_values(array_diff(array_unique(array_filter(array_map('intval', $userIds))), [$actor->id]));
        if ($ids === []) {
            return;
        }

        $actorName = $actor->name;

        DB::afterCommit(function () use ($meeting, $ids, $kind, $actorName) {
            User::query()->active()->whereIn('id', $ids)->get()
                ->filter(fn (User $to) => $this->visibility->canView($to, $meeting))
                ->each(fn (User $to) => $to->notify(new MeetingActivityNotification($meeting, $kind, $actorName)));
        });
    }
}
