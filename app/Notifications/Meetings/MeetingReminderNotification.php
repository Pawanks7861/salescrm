<?php

namespace App\Notifications\Meetings;

/** Sent from the SendMeetingReminder job (already queued), so not ShouldQueue itself. */
class MeetingReminderNotification extends MeetingNotification
{
    protected function event(): string
    {
        return 'meeting_reminder';
    }

    protected function message(): string
    {
        $minutes = (int) ceil(now()->diffInSeconds($this->meeting->start_at, false) / 60);

        $starts = match (true) {
            $minutes <= 0 => 'is starting now',
            $minutes < 60 => "starts in {$minutes} minute".($minutes === 1 ? '' : 's'),
            $minutes < 1440 && $minutes % 60 === 0 => 'starts in '.($minutes / 60).' hour'.($minutes === 60 ? '' : 's'),
            default => "starts at {$this->when()}",
        };

        return "{$this->subject()} {$starts}.";
    }
}
