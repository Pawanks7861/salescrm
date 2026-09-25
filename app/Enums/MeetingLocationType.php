<?php

namespace App\Enums;

/** Where a meeting happens. Behaviour keys off this enum, never off free-text location. */
enum MeetingLocationType: string
{
    case Office = 'office';
    case ClientLocation = 'client_location';
    case Online = 'online';
    case Phone = 'phone';
    case Site = 'site';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::ClientLocation => 'Client location',
            default => ucfirst($this->value),
        };
    }

    public function isPhysical(): bool
    {
        return in_array($this, [self::Office, self::ClientLocation, self::Site], true);
    }

    /** Default location type for a meeting type's location_mode. */
    public static function forMode(string $mode): self
    {
        return match ($mode) {
            'online' => self::Online,
            'phone' => self::Phone,
            'physical' => self::Office,
            default => self::Other,
        };
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $t) => ['value' => $t->value, 'label' => $t->label()], self::cases());
    }
}
