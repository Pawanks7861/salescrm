<?php

namespace App\Services\Batches;

use App\Services\NumberSequenceService;

/**
 * Generates batch numbers like BAT-2026-000001 from the shared row-locked
 * number_sequences table. The counter key is namespaced ("B:") so it never
 * shares a counter with lead or meeting numbers.
 */
class BatchNumberService
{
    public const PREFIX = 'BAT';

    public function __construct(private readonly NumberSequenceService $sequences) {}

    public function next(): string
    {
        $period = $this->sequences->currentYear();

        return sprintf('%s-%s-%06d', self::PREFIX, $period, $this->sequences->next('B:'.self::PREFIX, $period));
    }
}
