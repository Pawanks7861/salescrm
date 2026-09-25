<?php

namespace App\Http\Requests\Followups;

use App\Enums\FollowupOutcome;
use App\Enums\LeadPriority;
use App\Support\FollowupReminderOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteFollowupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outcome' => ['nullable', Rule::enum(FollowupOutcome::class)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'next_action' => ['nullable', 'string', 'max:255'],

            'schedule_next' => ['boolean'],
            'next' => ['nullable', 'array'],
            'next.followup_type_id' => ['required_if_accepted:schedule_next', 'nullable', 'integer'],
            'next.scheduled_date' => ['required_if_accepted:schedule_next', 'nullable', 'date_format:Y-m-d'],
            'next.scheduled_time' => ['required_if_accepted:schedule_next', 'nullable', 'date_format:H:i'],
            'next.assigned_to' => ['nullable', 'integer'],
            'next.priority' => ['nullable', Rule::enum(LeadPriority::class)],
            'next.reminder_minutes' => ['nullable', 'integer', 'min:0', 'max:'.FollowupReminderOptions::MAX_MINUTES],
            'next.title' => ['nullable', 'string', 'max:191'],
            'next.description' => ['nullable', 'string', 'max:5000'],
            'next.confirm_duplicate' => ['boolean'],

            'status_id' => ['nullable', 'integer'],
            'lost_reason_id' => ['nullable', 'integer'],
            'lost_reason_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'next.followup_type_id' => 'next follow-up type',
            'next.scheduled_date' => 'next follow-up date',
            'next.scheduled_time' => 'next follow-up time',
        ];
    }
}
