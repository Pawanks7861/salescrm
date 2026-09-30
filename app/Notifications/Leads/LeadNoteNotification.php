<?php

namespace App\Notifications\Leads;

use App\Models\Lead;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\Contracts\BrowserPushable;
use App\Support\PushEvent;
use Illuminate\Notifications\Notification;

/**
 * Someone else added a note the assignee is allowed to read.
 * Display data only — the lead page re-checks note visibility.
 */
class LeadNoteNotification extends Notification implements BrowserPushable
{
    public function __construct(public Lead $lead, public string $actorName) {}

    public function via(object $notifiable): array
    {
        return ['database', WebPushChannel::class, FcmChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        $name = $this->lead->full_name ?: 'a lead';

        return [
            'category' => 'lead',
            'event' => 'lead_note',
            'message' => "{$this->actorName} added a comment on {$name} (ID {$this->lead->id} · {$this->lead->lead_number})",
            'lead_id' => $this->lead->id,
            'lead_number' => $this->lead->lead_number,
            'url' => route('leads.show', $this->lead->id, false),
        ];
    }

    public function toBrowserPush(object $notifiable): ?array
    {
        return [
            'event' => PushEvent::COMMENT,
            'title' => 'New comment',
            'body' => "{$this->actorName} added a comment on a lead assigned to you.",
        ];
    }
}
