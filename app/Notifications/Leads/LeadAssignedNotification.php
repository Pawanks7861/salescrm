<?php

namespace App\Notifications\Leads;

use App\Models\Lead;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\Contracts\BrowserPushable;
use App\Services\SettingService;
use App\Support\PushEvent;
use Illuminate\Notifications\Notification;

/**
 * Lead (re)assigned to the notifiable by someone else or by an assignment
 * rule. Sent from the LeadAssigned event, so it covers manual, rule and
 * inbound assignment; Meta leads use FacebookLeadNotification instead.
 * Display data only — NotificationController re-checks lead visibility.
 */
class LeadAssignedNotification extends Notification implements BrowserPushable
{
    public function __construct(public Lead $lead, public bool $reassigned = false) {}

    public function via(object $notifiable): array
    {
        return ['database', WebPushChannel::class, FcmChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        $name = $this->lead->full_name ?: 'a lead';

        return [
            'category' => 'lead',
            'event' => 'lead_assigned',
            'message' => ($this->reassigned ? 'Lead reassigned to you: ' : 'New lead assigned to you: ')."{$name} (ID {$this->lead->id} · {$this->lead->lead_number})",
            'lead_id' => $this->lead->id,
            'lead_number' => $this->lead->lead_number,
            'url' => route('leads.show', $this->lead->id, false),
        ];
    }

    public function toBrowserPush(object $notifiable): ?array
    {
        return self::leadPush($this->lead);
    }

    /** Shared by manual and Meta lead notifications. */
    public static function leadPush(Lead $lead): array
    {
        $showNames = (bool) app(SettingService::class)->get('notifications.browser_show_names', true);
        $name = $lead->full_name ?: null;

        return [
            'event' => PushEvent::NEW_LEAD_ASSIGNED,
            'title' => 'New Lead Assigned',
            'body' => $showNames && $name ? "{$name} has been assigned to you." : 'A new lead has been assigned to you.',
        ];
    }
}
