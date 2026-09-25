<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadEnquiry extends Model
{
    public const CHANNEL_FACEBOOK = 'facebook';

    /** (channel, external_id) is unique: one enquiry per external submission. */
    protected $fillable = ['source_id', 'campaign_id', 'channel', 'external_id', 'enquiry_data_json', 'metadata_json', 'is_duplicate', 'received_at'];

    protected function casts(): array
    {
        return [
            'enquiry_data_json' => 'array',
            'metadata_json' => 'array',
            'is_duplicate' => 'boolean',
            'received_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'source_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
