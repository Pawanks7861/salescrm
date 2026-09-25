<?php

namespace App\Events;

use App\Models\Lead;
use Illuminate\Foundation\Events\Dispatchable;

class LeadCreated
{
    use Dispatchable;

    public function __construct(public readonly Lead $lead) {}
}
