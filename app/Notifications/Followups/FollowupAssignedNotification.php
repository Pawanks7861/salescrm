<?php

namespace App\Notifications\Followups;

use App\Models\Followup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class FollowupAssignedNotification extends FollowupNotification implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public function __construct(Followup $followup, public string $actorName)
    {
        parent::__construct($followup);
    }

    protected function event(): string
    {
        return 'followup_assigned';
    }

    protected function message(): string
    {
        $type = $this->followup->type?->name ?? 'follow-up';

        return "{$this->actorName} assigned you a {$type} follow-up for {$this->leadName()} on {$this->when()}.";
    }
}
