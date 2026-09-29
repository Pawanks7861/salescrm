<?php

namespace App\Notifications\Leads;

use App\Models\Lead;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\Contracts\BrowserPushable;
use Illuminate\Notifications\Notification;

/**
 * In-app notice for Meta leads. Display data only: NotificationController
 * re-checks lead visibility (via lead_id) before showing details or opening
 * the link. Contains no Meta answers, tokens or ids beyond the lead number.
 */
class FacebookLeadNotification extends Notification implements BrowserPushable
{
    public function __construct(
        public Lead $lead,
        public string $kind,
        public string $channel,
        public ?string $formName,
        public ?string $campaignName,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', WebPushChannel::class, FcmChannel::class];
    }

    /** Only new assignments are pushed; repeat enquiries stay in-app. */
    public function toBrowserPush(object $notifiable): ?array
    {
        return $this->kind === 'enquiry' ? null : LeadAssignedNotification::leadPush($this->lead);
    }

    public function toArray(object $notifiable): array
    {
        $name = $this->lead->full_name ?: 'a lead';

        return [
            'category' => 'lead',
            'event' => $this->kind === 'enquiry' ? 'facebook_enquiry' : 'facebook_lead_assigned',
            'message' => $this->kind === 'enquiry'
                ? "New {$this->channel} enquiry from existing lead: {$name} (ID {$this->lead->id})"
                : "New {$this->channel} lead assigned: {$name} (ID {$this->lead->id})",
            'lead_id' => $this->lead->id,
            'lead_number' => $this->lead->lead_number,
            'lead_name' => $name,
            'source' => $this->channel,
            'form' => $this->formName,
            'campaign' => $this->campaignName,
            'url' => route('leads.show', $this->lead->id, false),
        ];
    }
}
