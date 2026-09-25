<?php

namespace App\Services\Meetings;

use App\Services\NumberSequenceService;

/**
 * Generates meeting numbers like MTG-2026-000001. The counter key is
 * namespaced ("M:" + prefix) so it can never share a counter with lead
 * numbers, even if an admin configures the same prefix for both.
 */
class MeetingNumberService
{
    public function __construct(private readonly NumberSequenceService $sequences) {}

    public function next(): string
    {
        $prefix = $this->sequences->prefix('meeting.number_prefix', 'MTG');
        $period = $this->sequences->currentYear();

        return sprintf('%s-%s-%06d', $prefix, $period, $this->sequences->next('M:'.$prefix, $period));
    }
}
