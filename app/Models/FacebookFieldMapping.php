<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Maps one Meta form field to an allow-listed CRM destination:
 * target_type "lead" (lead_field), "custom" (lead_custom_field_id) or
 * "none" (kept only in the enquiry answers).
 */
class FacebookFieldMapping extends Model
{
    public const TARGET_LEAD = 'lead';

    public const TARGET_CUSTOM = 'custom';

    public const TARGET_NONE = 'none';

    protected $guarded = ['*'];

    public function form(): BelongsTo
    {
        return $this->belongsTo(FacebookForm::class, 'facebook_form_id');
    }

    public function customField(): BelongsTo
    {
        return $this->belongsTo(LeadCustomField::class, 'lead_custom_field_id');
    }
}
