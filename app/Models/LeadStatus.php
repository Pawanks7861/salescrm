<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeadStatus extends Model
{
    protected $fillable = [
        'name', 'description', 'color', 'icon', 'sort_order', 'probability', 'is_won', 'is_lost', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_won' => 'boolean',
            'is_lost' => 'boolean',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
            'probability' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'status_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public static function defaultStatus(): ?self
    {
        return static::active()->where('is_default', true)->first() ?? static::active()->ordered()->first();
    }
}
