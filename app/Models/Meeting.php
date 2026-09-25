<?php

namespace App\Models;

use App\Enums\LeadPriority;
use App\Enums\MeetingLocationType;
use App\Enums\MeetingOutcome;
use App\Enums\MeetingParticipantType;
use App\Enums\MeetingStatus;
use App\Services\Meetings\MeetingVisibility;
use Database\Factories\MeetingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Meeting extends Model
{
    /** @use HasFactory<MeetingFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Only descriptive fields are fillable. Number, lead, host, team, schedule,
     * status, completion/cancellation and audit columns are set by MeetingService.
     */
    protected $fillable = ['title', 'description', 'agenda', 'location_type', 'location', 'address', 'meeting_url', 'priority', 'reminder_offsets'];

    protected function casts(): array
    {
        return [
            'status' => MeetingStatus::class,
            'priority' => LeadPriority::class,
            'outcome' => MeetingOutcome::class,
            'location_type' => MeetingLocationType::class,
            'reminder_offsets' => 'array',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(MeetingType::class, 'meeting_type_id');
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id')->withTrashed();
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class)->withTrashed();
    }

    public function participants(): HasMany
    {
        return $this->hasMany(MeetingParticipant::class)->orderBy('id');
    }

    public function internalParticipants(): HasMany
    {
        return $this->hasMany(MeetingParticipant::class)->where('participant_type', MeetingParticipantType::User->value);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(MeetingReminder::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by')->withTrashed();
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by')->withTrashed();
    }

    public function rescheduledFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rescheduled_from_id')->withTrashed();
    }

    public function rescheduledTo(): HasOne
    {
        return $this->hasOne(self::class, 'rescheduled_from_id')->withTrashed();
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return app(MeetingVisibility::class)->apply($query, $user);
    }

    public function isUpcoming(): bool
    {
        return $this->status->isUpcoming();
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function durationMinutes(): int
    {
        return (int) round($this->start_at->diffInMinutes($this->end_at));
    }

    /** IDs of the host plus internal (user) participants who have not declined. */
    public function attendeeUserIds(): array
    {
        $participants = $this->relationLoaded('participants') ? $this->participants : $this->participants()->get();

        return $participants
            ->filter(fn (MeetingParticipant $p) => $p->participant_type === MeetingParticipantType::User && $p->user_id && $p->attendance_status?->value !== 'declined')
            ->pluck('user_id')
            ->push($this->host_user_id)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
