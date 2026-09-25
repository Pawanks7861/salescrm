<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A generated report file on the private disk. Never publicly addressable. */
class ReportExport extends Model
{
    public const QUEUED = 'queued';

    public const PROCESSING = 'processing';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    protected $guarded = ['id', 'uuid', 'user_id', 'disk', 'path'];

    protected $hidden = ['disk', 'path'];

    protected function casts(): array
    {
        return [
            'filters_json' => 'array',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
            'downloaded_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function isDownloadable(): bool
    {
        return $this->status === self::READY && $this->path && $this->expires_at?->isFuture();
    }
}
