<?php

namespace App\Notifications\Meetings;

use App\Models\Meeting;
use App\Support\CrmTime;
use Illuminate\Notifications\Notification;

/**
 * Base for meeting notifications. In-app only (database channel) for internal
 * users; external participants are never contacted in Phase 4.
 *
 * The payload is display data, never authorization: NotificationController
 * re-checks MeetingVisibility before showing it or following the link.
 */
abstract class MeetingNotification extends Notification
{
    public function __construct(public Meeting $meeting) {}

    abstract protected function event(): string;

    abstract protected function message(): string;

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'category' => 'meeting',
            'event' => $this->event(),
            'message' => $this->message(),
            'meeting_id' => $this->meeting->id,
            'lead_id' => $this->meeting->lead_id,
            'url' => route('meetings.show', $this->meeting->id, false),
        ];
    }

    /** "Product Demo with Amit Desai" / "Product Demo: Quarterly review". */
    protected function subject(): string
    {
        $type = $this->meeting->type?->name ?? 'Meeting';
        $lead = $this->meeting->lead?->full_name;

        return $lead ? "{$type} with {$lead}" : "{$type}: {$this->meeting->title}";
    }

    protected function when(): string
    {
        return CrmTime::format($this->meeting->start_at);
    }
}
