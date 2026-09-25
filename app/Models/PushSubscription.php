<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One browser's Web Push subscription. Endpoint and keys are encrypted at
 * rest, hidden from serialization and must never be logged or audited.
 */
class PushSubscription extends Model
{
    protected $guarded = ['id', 'user_id'];

    protected $hidden = ['endpoint', 'public_key', 'auth_token', 'endpoint_hash'];

    protected function casts(): array
    {
        return [
            'endpoint' => 'encrypted',
            'public_key' => 'encrypted',
            'auth_token' => 'encrypted',
            'last_used_at' => 'datetime',
        ];
    }

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
