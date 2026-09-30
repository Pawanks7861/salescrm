<?php

namespace App\Support;

/**
 * Event types carried in browser push payloads. The service worker and the
 * open CRM tab pick the notification sound from this value, so it must stay
 * in sync with resources/js/notifications/rules.js.
 */
final class PushEvent
{
    public const NEW_LEAD_ASSIGNED = 'NEW_LEAD_ASSIGNED';

    public const FOLLOWUP_REMINDER = 'FOLLOWUP_REMINDER';

    public const COMMENT = 'COMMENT';

    public const ALL = [self::NEW_LEAD_ASSIGNED, self::FOLLOWUP_REMINDER, self::COMMENT];
}
