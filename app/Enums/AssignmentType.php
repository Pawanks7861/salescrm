<?php

namespace App\Enums;

/** How an ownership change happened (stored on lead_assignments). */
enum AssignmentType: string
{
    case Manual = 'manual';
    case Automatic = 'automatic';
    case RoundRobin = 'round_robin';
    case Rule = 'rule';
    case Import = 'import';
    case Facebook = 'facebook';
}
