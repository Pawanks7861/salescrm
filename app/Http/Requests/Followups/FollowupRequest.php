<?php

namespace App\Http\Requests\Followups;

use App\Enums\LeadPriority;
use App\Support\FollowupReminderOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape validation for creating / editing a follow-up. Authorization, lead
 * access, assignee scope, past-time and duplicate rules live in
 * FollowupPolicy and FollowupService. created_by / updated_by / status are
 * never accepted from the client.
 */
class FollowupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'lead_id' => [Rule::requiredIf($creating), 'integer'],
            'followup_type_id' => [Rule::requiredIf($creating), 'integer'],
            'scheduled_date' => [Rule::requiredIf($creating), 'date_format:Y-m-d'],
            'scheduled_time' => [Rule::requiredIf($creating), 'date_format:H:i'],
            'assigned_to' => ['nullable', 'integer'],
            'priority' => ['nullable', Rule::enum(LeadPriority::class)],
            'reminder_minutes' => ['nullable', 'integer', 'min:0', 'max:'.FollowupReminderOptions::MAX_MINUTES],
            'title' => ['nullable', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:5000'],
            'confirm_duplicate' => ['boolean'],
        ];
    }

    /** Data for FollowupService (schedule is ignored on update: use reschedule). */
    public function payload(): array
    {
        $data = collect($this->validated())->except(['lead_id', 'confirm_duplicate']);

        if (! $this->isMethod('post')) {
            $data = $data->except(['scheduled_date', 'scheduled_time']);
        }

        return $data->all();
    }
}
