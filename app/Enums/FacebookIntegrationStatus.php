<?php

namespace App\Enums;

enum FacebookIntegrationStatus: string
{
    case Connected = 'connected';
    case NeedsReauthorization = 'needs_reauthorization';
    case PermissionMissing = 'permission_missing';
    case Error = 'error';
    case Disconnected = 'disconnected';

    public function label(): string
    {
        return match ($this) {
            self::Connected => 'Connected',
            self::NeedsReauthorization => 'Needs reauthorization',
            self::PermissionMissing => 'Permission missing',
            self::Error => 'Error',
            self::Disconnected => 'Disconnected',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Connected => 'green',
            self::NeedsReauthorization, self::PermissionMissing => 'amber',
            self::Error => 'red',
            self::Disconnected => 'slate',
        };
    }
}
