<?php

namespace App\Services\Followups;

use App\Models\Followup;
use App\Models\User;
use App\Notifications\Followups\FollowupAssignedNotification;
use App\Notifications\Followups\FollowupRescheduledNotification;
use Illuminate\Support\Facades\DB;

/**
 * User-triggered follow-up notifications. Dispatched after the surrounding
 * transaction commits (queued), and never to the person who made the change.
 */
class FollowupNotificationService
{
    public function assigned(Followup $followup, User $actor): void
    {
        $this->afterCommit($followup, $actor, fn (User $to) => $to->notify(new FollowupAssignedNotification($followup, $actor->name)));
    }

    public function rescheduled(Followup $followup, User $actor): void
    {
        $this->afterCommit($followup, $actor, fn (User $to) => $to->notify(new FollowupRescheduledNotification($followup, $actor->name)));
    }

    private function afterCommit(Followup $followup, User $actor, callable $send): void
    {
        if ($followup->assigned_to === null || (int) $followup->assigned_to === $actor->id) {
            return;
        }

        DB::afterCommit(function () use ($followup, $send) {
            $to = User::query()->active()->find($followup->assigned_to);
            if ($to) {
                $send($to);
            }
        });
    }
}
