<?php

namespace App\Models;

use App\Enums\CustomFieldType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeadCustomField extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'field_type', 'options_json', 'validation_rules_json', 'help_text',
        'is_required', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'field_type' => CustomFieldType::class,
            'options_json' => 'array',
            'validation_rules_json' => 'array',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /** @return array<string> */
    public function options(): array
    {
        return array_values(array_filter(array_map('strval', $this->options_json ?? []), fn ($o) => $o !== ''));
    }
}
