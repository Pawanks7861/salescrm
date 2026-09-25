<?php

namespace App\Enums;

/** Configured on meeting types; suggests the default location type in forms. */
enum MeetingLocationMode: string
{
    case Physical = 'physical';
    case Online = 'online';
    case Phone = 'phone';
    case Flexible = 'flexible';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $m) => ['value' => $m->value, 'label' => $m->label()], self::cases());
    }
}
