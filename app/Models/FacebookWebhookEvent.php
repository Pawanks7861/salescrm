<?php

namespace App\Models;

use App\Enums\FacebookEventStatus;
use App\Enums\MetaErrorCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per Meta lead (unique leadgen_id). `payload_json` holds only the
 * webhook identifiers (page/form/ad ids, created_time) — never answers/PII.
 */
class FacebookWebhookEvent extends Model
{
    public const ORIGIN_WEBHOOK = 'webhook';

    public const ORIGIN_SYNC = 'sync';

    public const ORIGIN_TEST = 'test';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'processing_status' => FacebookEventStatus::class,
            'error_category' => MetaErrorCategory::class,
            'payload_json' => 'array',
            'meta_created_at' => 'datetime',
            'received_at' => 'datetime',
            'last_delivered_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'processed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(FacebookPage::class, 'facebook_page_id');
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(FacebookForm::class, 'facebook_form_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class)->withTrashed();
    }

    public function isFailed(): bool
    {
        return $this->processing_status === FacebookEventStatus::Failed;
    }
}
