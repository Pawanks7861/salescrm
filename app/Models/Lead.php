<?php

namespace App\Models;

use App\Enums\LeadPriority;
use App\Services\Leads\LeadVisibility;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Only plain contact/business attributes are fillable. Ownership, status,
     * numbering, normalisation, conversion and audit columns are set exclusively
     * by the Lead services.
     */
    protected $fillable = [
        'first_name', 'last_name', 'email', 'phone', 'alternate_phone',
        'company_name', 'designation', 'city', 'state', 'country', 'pincode',
        'estimated_value', 'priority',
    ];

    protected function casts(): array
    {
        return [
            'priority' => LeadPriority::class,
            'estimated_value' => 'decimal:2',
            'is_duplicate' => 'boolean',
            'last_contacted_at' => 'datetime',
            'next_followup_at' => 'datetime',
            'converted_at' => 'datetime',
            'lost_at' => 'datetime',
        ];
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(LeadStatus::class, 'status_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'source_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to')->withTrashed();
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class)->withTrashed();
    }

    public function lostReason(): BelongsTo
    {
        return $this->belongsTo(LostReason::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'duplicate_of_id')->withTrashed();
    }

    public function enquiries(): HasMany
    {
        return $this->hasMany(LeadEnquiry::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(LeadAssignment::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(LeadNote::class);
    }

    public function followups(): HasMany
    {
        return $this->hasMany(Followup::class);
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class);
    }

    public function customFieldValues(): HasMany
    {
        return $this->hasMany(LeadCustomFieldValue::class);
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /** Restricts the query to leads the user is authorised to see (own / all). */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return app(LeadVisibility::class)->apply($query, $user);
    }

    public static function composeFullName(?string $first, ?string $last): string
    {
        return trim(trim((string) $first).' '.trim((string) $last));
    }
}
