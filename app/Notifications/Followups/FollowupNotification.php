<?php

namespace App\Notifications\Followups;

use App\Models\Followup;
use App\Support\CrmTime;
use Illuminate\Notifications\Notification;

/**
 * Base for follow-up notifications. Phase 3 delivers in-app only (database
 * channel); email / WhatsApp / push can be added by extending via().
 *
 * The payload is display data, never authorization: NotificationController
 * re-checks FollowupVisibility before showing lead details or following a link.
 */
abstract class FollowupNotification extends Notification
{
    public function __construct(public Followup $followup) {}

    abstract protected function event(): string;

    abstract protected function message(): string;

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'category' => 'followup',
            'event' => $this->event(),
            'message' => $this->message(),
            'followup_id' => $this->followup->id,
            'lead_id' => $this->followup->lead_id,
            'url' => route('followups.show', $this->followup->id, false),
        ];
    }

    protected function leadName(): string
    {
        return $this->followup->lead?->full_name ?: 'a lead';
    }

    protected function when(): string
    {
        return CrmTime::format($this->followup->scheduled_at);
    }
}
