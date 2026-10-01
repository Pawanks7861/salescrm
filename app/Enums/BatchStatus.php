<?php

namespace App\Enums;

/**
 * Archived batches stay viewable but cannot receive new leads and are left
 * out of the "add to batch" pickers.
 */
enum BatchStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Archived = 'archived';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'green',
            self::Inactive => 'amber',
            self::Archived => 'slate',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(bool $includeArchived = true): array
    {
        return array_values(array_map(
            fn (self $s) => ['value' => $s->value, 'label' => $s->label()],
            array_filter(self::cases(), fn (self $s) => $includeArchived || $s !== self::Archived),
        ));
    }
}
