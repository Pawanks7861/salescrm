<?php

namespace App\Models;

use App\Enums\CallRecordingStatus;
use App\Enums\CallRecordingStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Recording reference. The provider URL is encrypted and hidden; playback is
 * always proxied through the authorised CRM route.
 */
class CallRecording extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['provider_reference_encrypted', 'disk', 'path'];

    protected function casts(): array
    {
        return [
            'provider_reference_encrypted' => 'encrypted',
            'storage_type' => CallRecordingStorage::class,
            'status' => CallRecordingStatus::class,
            'file_size' => 'integer',
            'duration_seconds' => 'integer',
            'available_at' => 'datetime',
            'archived_at' => 'datetime',
            'expires_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    public function isPlayable(): bool
    {
        return $this->status === CallRecordingStatus::Available;
    }
}
