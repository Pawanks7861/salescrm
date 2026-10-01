<?php

namespace App\Models;

use App\Enums\BatchStatus;
use Database\Factories\BatchFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A named group of leads. Membership never changes a lead's owner, status or
 * visibility; who may see a lead inside a batch is decided by LeadVisibility.
 */
class Batch extends Model
{
    /** @use HasFactory<BatchFactory> */
    use HasFactory, SoftDeletes;

    /** Numbering, status changes and audit columns are set by BatchService. */
    protected $fillable = ['name', 'description', 'start_date', 'end_date'];

    protected function casts(): array
    {
        return [
            'status' => BatchStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function leads(): BelongsToMany
    {
        return $this->belongsToMany(Lead::class, 'batch_leads')->withPivot('added_by', 'created_at');
    }

    /** Includes deactivated / deleted users so past assignments stay visible; new ones must use User::eligibleTrainer(). */
    public function trainers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'batch_trainers', 'batch_id', 'trainer_id')
            ->withPivot('assigned_by', 'created_at')
            ->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by')->withTrashed();
    }

    public function isArchived(): bool
    {
        return $this->status === BatchStatus::Archived;
    }

    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->where('status', '!=', BatchStatus::Archived->value);
    }
}
