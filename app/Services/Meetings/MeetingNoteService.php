<?php

namespace App\Services\Meetings;

use App\Enums\AuditAction;
use App\Models\Meeting;
use App\Models\MeetingNote;
use App\Models\User;
use App\Notifications\Meetings\MeetingNoteNotification;
use App\Services\ActivityService;
use App\Services\AuditService;
use Illuminate\Support\Str;

class MeetingNoteService
{
    public function __construct(
        private readonly ActivityService $activities,
        private readonly AuditService $audit,
    ) {}

    public function add(Meeting $meeting, User $actor, string $body): MeetingNote
    {
        $note = new MeetingNote(['body' => $body]);
        $note->meeting_id = $meeting->id;
        $note->created_by = $actor->id;
        $note->save();

        $meeting->loadMissing(['type', 'lead']);

        if ($meeting->lead) {
            $this->activities->record(
                $meeting->lead,
                ActivityService::MEETING_NOTE,
                "{$actor->name} added a note on meeting {$meeting->meeting_number}.",
                ['meeting_id' => $meeting->id, 'meeting_note_id' => $note->id],
                $actor->id,
            );
        }

        $this->audit->log(
            AuditAction::MeetingNoteAdded,
            'meetings',
            $meeting,
            "{$actor->name} added a note on {$meeting->meeting_number}",
            null,
            ['meeting_note_id' => $note->id],
        );

        $this->notify($meeting, $actor, $body);

        return $note;
    }

    private function notify(Meeting $meeting, User $actor, string $body): void
    {
        $ids = array_values(array_unique(array_filter([
            $meeting->host_user_id ? (int) $meeting->host_user_id : null,
            $meeting->lead?->assigned_to ? (int) $meeting->lead->assigned_to : null,
        ])));

        $excerpt = Str::limit(trim(preg_replace('/\s+/', ' ', $body) ?? ''), 160);
        $recipients = User::query()->active()->whereIn('id', $ids)->whereKeyNot($actor->id)->get();

        foreach ($recipients as $recipient) {
            $recipient->notify(new MeetingNoteNotification($meeting, $actor->name, $excerpt));
        }
    }
}
