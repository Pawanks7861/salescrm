<?php

namespace App\Notifications\Meetings;

use App\Models\Meeting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Admin added a note on a meeting; the host and lead owner are told. */
class MeetingNoteNotification extends MeetingNotification implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public function __construct(Meeting $meeting, public string $actorName, public string $excerpt)
    {
        parent::__construct($meeting);
    }

    protected function event(): string
    {
        return 'meeting_note';
    }

    protected function message(): string
    {
        return "{$this->actorName} added a note on {$this->subject()}: {$this->excerpt}";
    }
}
