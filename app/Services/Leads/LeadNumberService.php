<?php

namespace App\Services\Leads;

use App\Services\NumberSequenceService;

/** Generates lead numbers like LD-2026-000001. */
class LeadNumberService
{
    public function __construct(private readonly NumberSequenceService $sequences) {}

    public function next(): string
    {
        $prefix = $this->sequences->prefix('lead.number_prefix', 'LD');
        $period = $this->sequences->currentYear();

        return sprintf('%s-%s-%06d', $prefix, $period, $this->sequences->next($prefix, $period));
    }
}
