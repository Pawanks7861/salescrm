<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CallDisposition extends Model
{
    protected $fillable = ['name', 'color', 'is_contact', 'requires_note', 'requires_next_action', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_contact' => 'boolean',
            'requires_note' => 'boolean',
            'requires_next_action' => 'boolean',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    public function calls(): HasMany
    {
        return $this->hasMany(Call::class, 'disposition_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
