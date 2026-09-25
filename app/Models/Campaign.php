<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Platform-agnostic campaign. `platform` + `external_id` identify campaigns
 * synced from ad platforms (facebook, instagram, google…); manual campaigns
 * have platform "manual" and no external id.
 */
class Campaign extends Model
{
    public const PLATFORMS = ['manual', 'facebook', 'instagram', 'google', 'other'];

    protected $fillable = [
        'name', 'source_id', 'platform', 'external_id', 'external_parent_id',
        'description', 'starts_at', 'ends_at', 'is_active', 'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'date',
            'ends_at' => 'date',
            'is_active' => 'boolean',
            'metadata_json' => 'array',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'source_id');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
