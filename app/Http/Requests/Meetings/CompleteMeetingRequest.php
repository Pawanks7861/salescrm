<?php

namespace App\Http\Requests\Meetings;

use App\Enums\LeadPriority;
use App\Enums\MeetingOutcome;
use App\Support\FollowupReminderOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outcome' => ['nullable', Rule::enum(MeetingOutcome::class)],
            'notes' => ['nullable', 'string', 'max:10000'],
            'attendance' => ['nullable', 'array', 'max:50'],
            'attendance.*' => ['nullable', Rule::in(['attended', 'absent'])],

            'status_id' => ['nullable', 'integer'],
            'lost_reason_id' => ['nullable', 'integer'],
            'lost_reason_notes' => ['nullable', 'string', 'max:1000'],

            'schedule_followup' => ['boolean'],
            'followup' => ['nullable', 'array'],
            'followup.followup_type_id' => ['required_if_accepted:schedule_followup', 'nullable', 'integer'],
            'followup.scheduled_date' => ['required_if_accepted:schedule_followup', 'nullable', 'date_format:Y-m-d'],
            'followup.scheduled_time' => ['required_if_accepted:schedule_followup', 'nullable', 'date_format:H:i'],
            'followup.assigned_to' => ['nullable', 'integer'],
            'followup.priority' => ['nullable', Rule::enum(LeadPriority::class)],
            'followup.reminder_minutes' => ['nullable', 'integer', 'min:0', 'max:'.FollowupReminderOptions::MAX_MINUTES],
            'followup.title' => ['nullable', 'string', 'max:191'],
            'followup.description' => ['nullable', 'string', 'max:5000'],
            'followup.confirm_duplicate' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'followup.followup_type_id' => 'follow-up type',
            'followup.scheduled_date' => 'follow-up date',
            'followup.scheduled_time' => 'follow-up time',
        ];
    }
}
