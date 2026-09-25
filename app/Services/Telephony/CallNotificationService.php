<?php

namespace App\Services\Telephony;

use App\Models\Call;
use App\Models\User;
use App\Notifications\Calls\MissedCallNotification;
use App\Services\SettingService;
use Illuminate\Support\Facades\DB;

/**
 * Call notifications (in-app). Sent after the surrounding transaction commits,
 * only to active users who can still see the call.
 */
class CallNotificationService
{
    public function __construct(
        private readonly CallVisibility $visibility,
        private readonly SettingService $settings,
    ) {}

    /** Missed incoming call → the responsible agent (the lead owner when nobody answered). */
    public function missed(Call $call): void
    {
        if (! $this->settings->get('telephony.notify_missed_calls', true) || ! $call->agent_user_id) {
            return;
        }

        $callId = $call->id;
        $userId = (int) $call->agent_user_id;

        DB::afterCommit(function () use ($callId, $userId) {
            $call = Call::query()->with('lead')->find($callId);
            $user = User::query()->active()->find($userId);

            if ($call && $user && $this->visibility->canView($user, $call)) {
                $user->notify(new MissedCallNotification($call));
            }
        });
    }
}
