<?php

namespace App\Models;

use App\Enums\FacebookIntegrationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The connected Meta account. The access token is encrypted at rest and
 * hidden from serialization; nothing is mass assignable.
 */
class FacebookIntegration extends Model
{
    use SoftDeletes;

    protected $guarded = ['*'];

    protected $hidden = ['access_token_encrypted'];

    protected function casts(): array
    {
        return [
            'access_token_encrypted' => 'encrypted',
            'status' => FacebookIntegrationStatus::class,
            'granted_scopes_json' => 'array',
            'missing_scopes_json' => 'array',
            'token_expires_at' => 'datetime',
            'data_access_expires_at' => 'datetime',
            'last_connected_at' => 'datetime',
            'last_verified_at' => 'datetime',
            'last_error_at' => 'datetime',
            'last_webhook_at' => 'datetime',
            'last_lead_at' => 'datetime',
            'disconnected_at' => 'datetime',
        ];
    }

    public function pages(): HasMany
    {
        return $this->hasMany(FacebookPage::class);
    }

    public function isConnected(): bool
    {
        return $this->status !== FacebookIntegrationStatus::Disconnected && filled($this->access_token_encrypted);
    }

    /** Connected with a valid token and all required permissions (last known state). */
    public function isHealthy(): bool
    {
        return $this->status === FacebookIntegrationStatus::Connected && filled($this->access_token_encrypted);
    }
}
