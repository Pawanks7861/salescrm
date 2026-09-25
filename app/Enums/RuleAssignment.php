<?php

namespace App\Enums;

enum RuleAssignment: string
{
    case User = 'user';
    case RoundRobin = 'round_robin';

    /** Legacy: team-based rules are kept for history but never run and cannot be created. */
    case Team = 'team';
    case TeamRoundRobin = 'team_round_robin';

    public function label(): string
    {
        return match ($this) {
            self::User => 'Specific user',
            self::RoundRobin => 'Round robin (selected users)',
            self::Team => 'Team (deprecated, disabled)',
            self::TeamRoundRobin => 'Team round robin (deprecated, disabled)',
        };
    }

    public function isDeprecated(): bool
    {
        return in_array($this, [self::Team, self::TeamRoundRobin], true);
    }

    /** @return list<self> */
    public static function supported(): array
    {
        return [self::User, self::RoundRobin];
    }
}
