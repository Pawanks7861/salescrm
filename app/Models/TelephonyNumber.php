<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelephonyNumber extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'supports_inbound' => 'boolean',
            'supports_outbound' => 'boolean',
            'supports_webrtc' => 'boolean',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'metadata_json' => 'array',
        ];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(TelephonyIntegration::class, 'integration_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class)->withTrashed();
    }
}
