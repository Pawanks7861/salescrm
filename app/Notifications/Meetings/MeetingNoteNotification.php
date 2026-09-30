<?php

namespace App\Notifications\Meetings;

use App\Models\Meeting;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\Contracts\BrowserPushable;
use App\Support\PushEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Admin added a note on a meeting; the host and lead owner are told. */
class MeetingNoteNotification extends MeetingNotification implements BrowserPushable, ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public function __construct(Meeting $meeting, public string $actorName, public string $excerpt)
    {
        parent::__construct($meeting);
    }

    public function via(object $notifiable): array
    {
        return ['database', WebPushChannel::class, FcmChannel::class];
    }

    public function toBrowserPush(object $notifiable): ?array
    {
        return [
            'event' => PushEvent::COMMENT,
            'title' => 'New comment',
            'body' => "{$this->actorName} added a note on {$this->subject()}.",
        ];
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
