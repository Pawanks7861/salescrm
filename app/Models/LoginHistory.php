<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoginHistory extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'email', 'event', 'successful', 'ip_address', 'user_agent',
        'browser', 'platform', 'device', 'session_id', 'logged_in_at', 'logged_out_at',
    ];

    protected function casts(): array
    {
        return [
            'successful' => 'boolean',
            'logged_in_at' => 'datetime',
            'logged_out_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
