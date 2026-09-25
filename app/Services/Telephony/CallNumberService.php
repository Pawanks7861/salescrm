<?php

namespace App\Services\Telephony;

use App\Services\NumberSequenceService;

/**
 * Generates call numbers like CALL-2026-000001 from the row-locked
 * `number_sequences` counter. The key is namespaced ("C:" + prefix) so it can
 * never share a counter with lead or meeting numbers.
 */
class CallNumberService
{
    public function __construct(private readonly NumberSequenceService $sequences) {}

    public function next(): string
    {
        $prefix = $this->sequences->prefix('telephony.number_prefix', 'CALL');
        $period = $this->sequences->currentYear();

        return sprintf('%s-%s-%06d', $prefix, $period, $this->sequences->next('C:'.$prefix, $period));
    }
}
