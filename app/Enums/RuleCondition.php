<?php

namespace App\Enums;

enum RuleCondition: string
{
    case Any = 'any';
    case Source = 'source';
    case Campaign = 'campaign';
    case City = 'city';
    case State = 'state';
    case FacebookForm = 'facebook_form';

    /** Legacy: "lead team" condition is kept for history but never matches and cannot be created. */
    case Team = 'team';

    public function label(): string
    {
        return match ($this) {
            self::Any => 'Any lead (default rule)',
            self::FacebookForm => 'Facebook form',
            self::Team => 'Team (deprecated, disabled)',
            default => ucfirst($this->value),
        };
    }

    public function isDeprecated(): bool
    {
        return $this === self::Team;
    }

    /** @return list<self> */
    public static function supported(): array
    {
        return array_values(array_filter(self::cases(), fn (self $c) => ! $c->isDeprecated()));
    }
}
