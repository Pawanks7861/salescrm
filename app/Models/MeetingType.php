<?php

namespace App\Models;

use App\Enums\MeetingLocationMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeetingType extends Model
{
    protected $fillable = ['name', 'icon', 'color', 'location_mode', 'default_duration_minutes', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return [
            'location_mode' => MeetingLocationMode::class,
            'default_duration_minutes' => 'integer',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
