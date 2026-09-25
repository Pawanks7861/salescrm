<?php

namespace App\Services;

use App\Models\Activity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Records business timeline events ("Rahul changed status to Qualified").
 * Separate from AuditService: activities are user-facing history shown on a
 * record, audit logs are the security trail reviewed by administrators.
 */
class ActivityService
{
    public const LEAD_CREATED = 'lead_created';

    public const LEAD_UPDATED = 'lead_updated';

    public const LEAD_ASSIGNED = 'lead_assigned';

    public const STATUS_CHANGED = 'status_changed';

    public const PRIORITY_CHANGED = 'priority_changed';

    public const LEAD_WON = 'lead_won';

    public const LEAD_LOST = 'lead_lost';

    public const LEAD_REOPENED = 'lead_reopened';

    public const LEAD_ARCHIVED = 'lead_archived';

    public const LEAD_RESTORED = 'lead_restored';

    public const DUPLICATE_FLAGGED = 'duplicate_flagged';

    public const ENQUIRY_RECEIVED = 'enquiry_received';

    public const NOTE_ADDED = 'note_added';

    public const NOTE_EDITED = 'note_edited';

    public const NOTE_DELETED = 'note_deleted';

    public const ATTACHMENT_UPLOADED = 'attachment_uploaded';

    public const ATTACHMENT_DELETED = 'attachment_deleted';

    public const CUSTOM_FIELDS_UPDATED = 'custom_fields_updated';

    public const FOLLOWUP_CREATED = 'followup_created';

    public const FOLLOWUP_COMPLETED = 'followup_completed';

    public const FOLLOWUP_RESCHEDULED = 'followup_rescheduled';

    public const FOLLOWUP_CANCELLED = 'followup_cancelled';

    public const MEETING_CREATED = 'meeting_created';

    public const MEETING_CONFIRMED = 'meeting_confirmed';

    public const MEETING_RESCHEDULED = 'meeting_rescheduled';

    public const MEETING_CANCELLED = 'meeting_cancelled';

    public const MEETING_COMPLETED = 'meeting_completed';

    public const MEETING_NO_SHOW = 'meeting_no_show';

    public const CALL_STARTED = 'call_started';

    public const CALL_COMPLETED = 'call_completed';

    public const CALL_UNANSWERED = 'call_unanswered';

    public const CALL_MISSED = 'call_missed';

    public const CALL_OUTCOME = 'call_outcome';

    public function record(Model $subject, string $type, string $description, array $properties = [], ?int $userId = null): Activity
    {
        return Activity::create([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'user_id' => $userId ?? Auth::id(),
            'type' => $type,
            'description' => $description,
            'properties' => $properties === [] ? null : $properties,
        ]);
    }
}
