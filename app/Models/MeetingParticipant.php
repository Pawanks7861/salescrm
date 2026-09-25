<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Enums\MeetingParticipantType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A meeting attendee: an internal CRM user, the meeting's lead, or an external
 * contact. name/email/phone are a snapshot taken when the participant was
 * added, so historical invitation records stay accurate if the source changes.
 */
class MeetingParticipant extends Model
{
    public const INVITE_NOT_SENT = 'not_sent';

    public const INVITE_NOTIFIED = 'notified';

    /** Rows are only built by MeetingParticipantService from resolved, authorized values. */
    protected $fillable = [
        'participant_type',
        'user_id',
        'lead_id',
        'name',
        'email',
        'phone',
        'attendance_status',
        'invitation_status',
    ];

    protected function casts(): array
    {
        return [
            'participant_type' => MeetingParticipantType::class,
            'attendance_status' => AttendanceStatus::class,
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class)->withTrashed();
    }
}
