<?php

namespace App\Http\Presenters;

use App\Enums\LeadAgeBucket;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\User;
use App\Support\LeadValue;

/** Shapes lead data for Inertia pages; exposes only what the UI needs. */
class LeadPresenter
{
    /** Relations required by row(). */
    public const ROW_WITH = [
        'status:id,name,color,is_won,is_lost',
        'source:id,name,color',
        'campaign:id,name',
        'assignee:id,name',
    ];

    public function row(Lead $lead, User $viewer): array
    {
        $ageDays = $this->ageDays($lead);

        return [
            'id' => $lead->id,
            'lead_number' => $lead->lead_number,
            'full_name' => $lead->full_name,
            'company_name' => $lead->company_name,
            'phone' => $lead->phone,
            'email' => $lead->email,
            'city' => $lead->city,
            'state' => $lead->state,
            'priority' => $lead->priority?->value,
            'status' => $lead->status?->only('id', 'name', 'color', 'is_won', 'is_lost'),
            'source' => $lead->source?->only('id', 'name', 'color'),
            'campaign' => $lead->campaign?->only('id', 'name'),
            'assignee' => $lead->assignee?->only('id', 'name'),
            'is_duplicate' => $lead->is_duplicate,
            'age_days' => $ageDays,
            'age_bucket' => LeadAgeBucket::forDays($ageDays)->value,
            ...(LeadValue::enabled() ? ['estimated_value' => $lead->estimated_value] : []),
            'next_followup_at' => $lead->next_followup_at?->toIso8601String(),
            'created_at' => $lead->created_at?->toIso8601String(),
            'updated_at' => $lead->updated_at?->toIso8601String(),
            'archived' => $lead->trashed(),
        ];
    }

    public function card(Lead $lead): array
    {
        return [
            'id' => $lead->id,
            'lead_number' => $lead->lead_number,
            'full_name' => $lead->full_name,
            'company_name' => $lead->company_name,
            'priority' => $lead->priority?->value,
            'source' => $lead->source?->name,
            'assignee' => $lead->assignee?->only('id', 'name'),
            ...(LeadValue::enabled() ? ['estimated_value' => $lead->estimated_value] : []),
            'age_days' => $this->ageDays($lead),
            'status_id' => $lead->status_id,
        ];
    }

    public function activity(Activity $activity): array
    {
        return [
            'id' => $activity->id,
            'type' => $activity->type,
            'description' => $activity->description,
            'user' => $activity->user?->only('id', 'name'),
            'created_at' => $activity->created_at?->toIso8601String(),
        ];
    }

    public function ageDays(Lead $lead): int
    {
        return $lead->created_at ? (int) floor($lead->created_at->diffInDays(now(), true)) : 0;
    }
}
