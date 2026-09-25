<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only status-transition history (written by LeadService). The first
 * row of a lead has from_status_id = null (its initial status at creation).
 */
class LeadStatusChange extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['changed_at' => 'datetime', 'is_backfilled' => 'boolean'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class)->withTrashed();
    }

    public function toStatus(): BelongsTo
    {
        return $this->belongsTo(LeadStatus::class, 'to_status_id');
    }

    public static function record(Lead $lead, ?int $fromStatusId, int $toStatusId, ?int $changedBy, $at = null): self
    {
        return self::query()->create([
            'lead_id' => $lead->id,
            'from_status_id' => $fromStatusId,
            'to_status_id' => $toStatusId,
            'changed_at' => $at ?? now(),
            'changed_by' => $changedBy,
            'assigned_to' => $lead->assigned_to,
        ]);
    }
}
