<?php

namespace App\Models;

use App\Enums\TelephonyCallingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Provider-level telephony configuration. Credentials are NOT stored here —
 * they live in the environment. `configuration_encrypted` only holds
 * non-secret provider options and is hidden from serialization anyway.
 */
class TelephonyIntegration extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['configuration_encrypted'];

    protected function casts(): array
    {
        return [
            'configuration_encrypted' => 'encrypted:array',
            'is_active' => 'boolean',
            'browser_calling_enabled' => 'boolean',
            'pstn_calling_enabled' => 'boolean',
            'recording_enabled' => 'boolean',
            'default_calling_mode' => TelephonyCallingMode::class,
            'last_health_check_at' => 'datetime',
            'last_error_at' => 'datetime',
            'last_callback_at' => 'datetime',
        ];
    }

    public function numbers(): HasMany
    {
        return $this->hasMany(TelephonyNumber::class, 'integration_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(TelephonyUser::class, 'integration_id');
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return ($this->configuration_encrypted ?? [])[$key] ?? $default;
    }

    public function modeEnabled(TelephonyCallingMode $mode): bool
    {
        return $mode === TelephonyCallingMode::WebRtc ? $this->browser_calling_enabled : $this->pstn_calling_enabled;
    }
}
