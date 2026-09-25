<?php

namespace App\Notifications\Followups;

use App\Models\Followup;
use App\Models\FollowupReminder;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\Contracts\BrowserPushable;
use App\Services\SettingService;
use App\Support\CrmTime;
use App\Support\PushEvent;

/**
 * Sent from the SendFollowupReminder job (already queued), so not ShouldQueue
 * itself. The reminder row makes it idempotent, so the browser push rides on
 * the same single delivery.
 */
class FollowupReminderNotification extends FollowupNotification implements BrowserPushable
{
    public function __construct(Followup $followup, public string $kind)
    {
        parent::__construct($followup);
    }

    public function via(object $notifiable): array
    {
        return ['database', WebPushChannel::class];
    }

    /** Only the "before due" reminder is pushed; overdue alerts stay in-app. */
    public function toBrowserPush(object $notifiable): ?array
    {
        if ($this->kind === FollowupReminder::KIND_OVERDUE) {
            return null;
        }

        $showNames = (bool) app(SettingService::class)->get('notifications.browser_show_names', true);
        $type = $this->followup->type?->name ?: 'Follow-up';
        $time = CrmTime::format($this->followup->scheduled_at, 'g:i A');
        $name = $this->followup->lead?->full_name;

        return [
            'event' => PushEvent::FOLLOWUP_REMINDER,
            'title' => 'Follow-up Reminder',
            'body' => $showNames && $name ? "{$type} with {$name} at {$time}." : "Your follow-up is due at {$time}.",
        ];
    }

    protected function event(): string
    {
        return $this->kind === FollowupReminder::KIND_OVERDUE ? 'followup_overdue' : 'followup_reminder';
    }

    protected function message(): string
    {
        if ($this->kind === FollowupReminder::KIND_OVERDUE) {
            return "Follow-up with {$this->leadName()} is overdue.";
        }

        $minutes = (int) ceil(now()->diffInSeconds($this->followup->scheduled_at, false) / 60);

        $due = match (true) {
            $minutes <= 0 => 'is due now',
            $minutes < 60 => "is due in {$minutes} minute".($minutes === 1 ? '' : 's'),
            $minutes < 1440 && $minutes % 60 === 0 => 'is due in '.($minutes / 60).' hour'.($minutes === 60 ? '' : 's'),
            default => "is due at {$this->when()}",
        };

        return "Follow-up with {$this->leadName()} {$due}.";
    }
}
