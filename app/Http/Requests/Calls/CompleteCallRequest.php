<?php

namespace App\Http\Requests\Calls;

use App\Enums\LeadPriority;
use App\Enums\MeetingLocationType;
use App\Support\FollowupReminderOptions;
use App\Support\MeetingReminderOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape validation for the post-call outcome. Permissions, lead access,
 * required notes / next action and the follow-up / meeting / status rules are
 * enforced by CallCompletionService and the Phase 2–4 services it calls.
 */
class CompleteCallRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'disposition_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'next_action' => ['nullable', Rule::in(['none', 'followup', 'meeting'])],

            'status_id' => ['nullable', 'integer'],
            'lost_reason_id' => ['nullable', 'integer'],
            'lost_reason_notes' => ['nullable', 'string', 'max:1000'],

            'followup' => ['nullable', 'array'],
            'followup.followup_type_id' => ['required_if:next_action,followup', 'nullable', 'integer'],
            'followup.scheduled_date' => ['required_if:next_action,followup', 'nullable', 'date_format:Y-m-d'],
            'followup.scheduled_time' => ['required_if:next_action,followup', 'nullable', 'date_format:H:i'],
            'followup.priority' => ['nullable', Rule::enum(LeadPriority::class)],
            'followup.reminder_minutes' => ['nullable', 'integer', 'min:0', 'max:'.FollowupReminderOptions::MAX_MINUTES],
            'followup.title' => ['nullable', 'string', 'max:191'],
            'followup.description' => ['nullable', 'string', 'max:5000'],
            'followup.confirm_duplicate' => ['boolean'],

            'meeting' => ['nullable', 'array'],
            'meeting.meeting_type_id' => ['required_if:next_action,meeting', 'nullable', 'integer'],
            'meeting.title' => ['nullable', 'string', 'max:191'],
            'meeting.scheduled_date' => ['required_if:next_action,meeting', 'nullable', 'date_format:Y-m-d'],
            'meeting.start_time' => ['required_if:next_action,meeting', 'nullable', 'date_format:H:i'],
            'meeting.end_time' => ['required_if:next_action,meeting', 'nullable', 'date_format:H:i'],
            'meeting.location_type' => ['nullable', Rule::enum(MeetingLocationType::class)],
            'meeting.location' => ['nullable', 'string', 'max:191'],
            'meeting.address' => ['nullable', 'string', 'max:500'],
            'meeting.meeting_url' => ['nullable', 'url:http,https', 'max:500'],
            'meeting.agenda' => ['nullable', 'string', 'max:5000'],
            'meeting.priority' => ['nullable', Rule::enum(LeadPriority::class)],
            'meeting.reminders' => ['nullable', 'array', 'max:6'],
            'meeting.reminders.*' => ['integer', Rule::in(array_keys(MeetingReminderOptions::OPTIONS))],
            'meeting.override_conflict' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'disposition_id' => 'outcome',
            'followup.followup_type_id' => 'follow-up type',
            'followup.scheduled_date' => 'follow-up date',
            'followup.scheduled_time' => 'follow-up time',
            'meeting.meeting_type_id' => 'meeting type',
            'meeting.scheduled_date' => 'meeting date',
            'meeting.start_time' => 'start time',
            'meeting.end_time' => 'end time',
        ];
    }
}
