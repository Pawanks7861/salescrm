<?php

namespace App\Models;

use App\Enums\CallEventStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Raw provider callback ledger (sanitised payload encrypted at rest). */
class CallEvent extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['payload_encrypted'];

    protected function casts(): array
    {
        return [
            'payload_encrypted' => 'encrypted:array',
            'processing_status' => CallEventStatus::class,
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }
}
