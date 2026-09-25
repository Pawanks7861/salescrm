<?php

namespace App\Notifications\Meetings;

use App\Models\Meeting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/** User-triggered meeting changes: assigned, scheduled (invited), rescheduled, cancelled, completed. */
class MeetingActivityNotification extends MeetingNotification implements ShouldQueue
{
    use Queueable;

    public const ASSIGNED = 'meeting_assigned';

    public const SCHEDULED = 'meeting_scheduled';

    public const RESCHEDULED = 'meeting_rescheduled';

    public const CANCELLED = 'meeting_cancelled';

    public const COMPLETED = 'meeting_completed';

    public bool $deleteWhenMissingModels = true;

    public function __construct(Meeting $meeting, public string $kind, public string $actorName)
    {
        parent::__construct($meeting);
    }

    protected function event(): string
    {
        return $this->kind;
    }

    protected function message(): string
    {
        return match ($this->kind) {
            self::ASSIGNED => "{$this->actorName} made you the host of {$this->subject()} on {$this->when()}.",
            self::SCHEDULED => "{$this->actorName} invited you to {$this->subject()} on {$this->when()}.",
            self::RESCHEDULED => "{$this->actorName} rescheduled {$this->subject()} to {$this->when()}.",
            self::CANCELLED => "{$this->actorName} cancelled {$this->subject()} scheduled for {$this->when()}.",
            default => "{$this->actorName} completed {$this->subject()}.",
        };
    }
}
