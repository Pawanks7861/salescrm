<?php

namespace App\Services\Batches;

/** Outcome of adding or removing leads: how many were selected and how many actually changed. */
final class BatchMembershipResult
{
    public function __construct(
        public readonly int $selected,
        public readonly int $changed,
    ) {}

    public static function none(): self
    {
        return new self(0, 0);
    }

    public function unchanged(): int
    {
        return $this->selected - $this->changed;
    }

    public function addedMessage(string $batchName): string
    {
        if ($this->selected === 0) {
            return "Batch {$batchName} saved.";
        }

        $parts = ["{$this->selected} selected", "{$this->changed} added to {$batchName}"];
        if ($this->unchanged() > 0) {
            $parts[] = "{$this->unchanged()} already in the batch";
        }

        return implode(' · ', $parts).'.';
    }

    public function removedMessage(string $batchName): string
    {
        $noun = $this->changed === 1 ? 'lead' : 'leads';

        return "{$this->changed} {$noun} removed from {$batchName}. The leads themselves were not changed.";
    }

    /** @return array{selected: int, changed: int, unchanged: int} */
    public function toArray(): array
    {
        return ['selected' => $this->selected, 'changed' => $this->changed, 'unchanged' => $this->unchanged()];
    }
}
