<?php

namespace App\Events;

use App\Models\Lead;
use Illuminate\Foundation\Events\Dispatchable;

class LeadAssigned
{
    use Dispatchable;

    public function __construct(
        public readonly Lead $lead,
        public readonly ?int $fromUserId,
        public readonly ?int $toUserId,
        public readonly ?int $assignedBy,
    ) {}
}
