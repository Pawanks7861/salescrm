<?php

namespace App\Models;

use App\Enums\FollowupOutcome;
use App\Enums\FollowupStatus;
use App\Enums\LeadPriority;
use App\Services\Followups\FollowupVisibility;
use Database\Factories\FollowupFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Followup extends Model
{
    /** @use HasFactory<FollowupFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Only descriptive fields are fillable. Lead, owner, schedule, status,
     * completion/cancellation and audit columns are set by FollowupService.
     */
    protected $fillable = ['title', 'description', 'priority', 'reminder_minutes_before'];

    protected function casts(): array
    {
        return [
            'status' => FollowupStatus::class,
            'priority' => LeadPriority::class,
            'outcome' => FollowupOutcome::class,
            'scheduled_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'reminder_minutes_before' => 'integer',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(FollowupType::class, 'followup_type_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to')->withTrashed();
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class)->withTrashed();
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

    public function reminders(): HasMany
    {
        return $this->hasMany(FollowupReminder::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return app(FollowupVisibility::class)->apply($query, $user);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', FollowupStatus::Pending->value);
    }

    public function isPending(): bool
    {
        return $this->status === FollowupStatus::Pending;
    }

    public function isOverdue(): bool
    {
        return $this->isPending() && $this->scheduled_at->lt(now());
    }

    public function displayTitle(): string
    {
        return $this->title ?: ($this->type?->name ?? 'Follow-up');
    }
}
