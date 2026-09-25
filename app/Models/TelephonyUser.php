<?php

namespace App\Models;

use App\Enums\TelephonyCallingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Maps a CRM user to their provider identity. No SIP password is stored:
 * browser calling obtains per-user credentials through the provider SDK.
 */
class TelephonyUser extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'calling_mode' => TelephonyCallingMode::class,
            'is_enabled' => 'boolean',
            'last_registered_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'metadata_json' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(TelephonyIntegration::class, 'integration_id');
    }
}
