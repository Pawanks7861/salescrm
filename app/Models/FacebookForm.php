<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A Meta Instant Form. `form_id` (per Page) is the stable key, never the name. */
class FacebookForm extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'questions_json' => 'array',
            'metadata_json' => 'array',
            'last_synced_at' => 'datetime',
            'last_lead_at' => 'datetime',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(FacebookPage::class, 'facebook_page_id');
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(FacebookFieldMapping::class);
    }

    public function leadSource(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'lead_source_id');
    }

    /** @return array<int, array{key: string, label: string, type: ?string}> */
    public function questions(): array
    {
        return array_values(array_filter($this->questions_json ?? [], fn ($q) => is_array($q) && filled($q['key'] ?? null)));
    }
}
