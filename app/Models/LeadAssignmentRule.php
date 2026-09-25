<?php

namespace App\Models;

use App\Enums\RuleAssignment;
use App\Enums\RuleCondition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeadAssignmentRule extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'condition_type', 'condition_value', 'assignment_type',
        'assigned_user_id', 'assigned_team_id', 'user_pool_json', 'priority', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'condition_type' => RuleCondition::class,
            'assignment_type' => RuleAssignment::class,
            'user_pool_json' => 'array',
            'is_active' => 'boolean',
            'priority' => 'integer',
        ];
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id')->withTrashed();
    }

    public function assignedTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'assigned_team_id')->withTrashed();
    }

    /** Team-based rules no longer run (team visibility was removed). */
    public function isDeprecated(): bool
    {
        return $this->assignment_type?->isDeprecated() || $this->condition_type?->isDeprecated();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('priority')->orderBy('id');
    }
}
