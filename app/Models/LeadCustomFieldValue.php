<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadCustomFieldValue extends Model
{
    protected $fillable = ['lead_id', 'lead_custom_field_id', 'value'];

    public function field(): BelongsTo
    {
        return $this->belongsTo(LeadCustomField::class, 'lead_custom_field_id')->withTrashed();
    }
}
