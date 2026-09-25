<?php

namespace App\Events;

use App\Models\Lead;
use Illuminate\Foundation\Events\Dispatchable;

class LeadStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Lead $lead,
        public readonly int $fromStatusId,
        public readonly int $toStatusId,
    ) {}
}
