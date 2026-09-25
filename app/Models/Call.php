<?php

namespace App\Models;

use App\Enums\CallChannel;
use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Services\Telephony\CallVisibility;
use Database\Factories\CallFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Canonical call record. Nothing is mass assignable: provider identifiers,
 * timestamps, durations, direction, agent and status are written only by the
 * telephony services; users may change disposition and notes through
 * CallCompletionService.
 */
class Call extends Model
{
    /** @use HasFactory<CallFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'direction' => CallDirection::class,
            'channel' => CallChannel::class,
            'status' => CallStatus::class,
            'requires_disposition' => 'boolean',
            'started_at' => 'datetime',
            'ringing_at' => 'datetime',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
            'disposition_at' => 'datetime',
            'notes_updated_at' => 'datetime',
            'last_event_at' => 'datetime',
            'reconciled_at' => 'datetime',
            'ring_duration_seconds' => 'integer',
            'talk_duration_seconds' => 'integer',
            'total_duration_seconds' => 'integer',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class)->withTrashed();
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id')->withTrashed();
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class)->withTrashed();
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(TelephonyIntegration::class, 'integration_id');
    }

    public function number(): BelongsTo
    {
        return $this->belongsTo(TelephonyNumber::class, 'telephony_number_id');
    }

    public function disposition(): BelongsTo
    {
        return $this->belongsTo(CallDisposition::class, 'disposition_id');
    }

    public function dispositionBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disposition_by')->withTrashed();
    }

    public function followup(): BelongsTo
    {
        return $this->belongsTo(Followup::class)->withTrashed();
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class)->withTrashed();
    }

    public function recording(): HasOne
    {
        return $this->hasOne(CallRecording::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(CallEvent::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return app(CallVisibility::class)->apply($query, $user);
    }

    /** Connected calls still waiting for the agent's disposition. */
    public function scopeAwaitingDisposition(Builder $query): Builder
    {
        return $query->where('requires_disposition', true)->whereNull('disposition_id');
    }

    public function customerNumber(): ?string
    {
        return $this->direction === CallDirection::Inbound ? $this->from_number : $this->to_number;
    }

    public function durationLabel(): ?string
    {
        $seconds = $this->talk_duration_seconds;
        if (! $seconds) {
            return null;
        }

        return $seconds >= 3600
            ? sprintf('%dh %dm %ds', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60)
            : sprintf('%dm %ds', intdiv($seconds, 60), $seconds % 60);
    }
}
