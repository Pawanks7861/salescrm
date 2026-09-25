<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A Meta Page available to the connected account. `page_id` is the stable key. */
class FacebookPage extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['page_access_token_encrypted'];

    protected function casts(): array
    {
        return [
            'page_access_token_encrypted' => 'encrypted',
            'tasks_json' => 'array',
            'is_selected' => 'boolean',
            'is_subscribed' => 'boolean',
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
            'last_subscription_check_at' => 'datetime',
        ];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(FacebookIntegration::class, 'facebook_integration_id');
    }

    public function forms(): HasMany
    {
        return $this->hasMany(FacebookForm::class);
    }

    /** Pages that should deliver leads into the CRM. */
    public function scopeReceiving(Builder $query): Builder
    {
        return $query->where('is_selected', true)->where('is_active', true);
    }

    public function receivesLeads(): bool
    {
        return $this->is_selected && $this->is_active;
    }
}
