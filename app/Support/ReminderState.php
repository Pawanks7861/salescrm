<?php

namespace App\Support;

/** Status values shared by every reminder table (followup_reminders, meeting_reminders). */
final class ReminderState
{
    public const PENDING = 'pending';

    public const PROCESSING = 'processing';

    public const SENT = 'sent';

    public const CANCELLED = 'cancelled';

    public const FAILED = 'failed';

    public const MAX_ATTEMPTS = 3;

    public const STALE_PROCESSING_MINUTES = 10;
}
