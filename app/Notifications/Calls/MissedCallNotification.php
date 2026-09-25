<?php

namespace App\Notifications\Calls;

use App\Models\Call;
use App\Services\SettingService;
use App\Support\CrmTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * In-app "Missed call from Amit Desai at 3:14 PM." The payload is display
 * data only: NotificationController re-checks CallVisibility before showing
 * it or following the link.
 */
class MissedCallNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Call $call) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $who = $this->call->lead?->full_name ?: 'an unknown caller';
        $time = CrmTime::format($this->call->started_at, (string) app(SettingService::class)->get('general.time_format', 'h:i A'));

        return [
            'category' => 'call',
            'event' => 'call_missed',
            'message' => "Missed call from {$who} at {$time}.",
            'call_id' => $this->call->id,
            'lead_id' => $this->call->lead_id,
            'url' => route('calls.show', $this->call->id, false),
        ];
    }
}
