<?php

namespace App\Http\Requests\Meetings;

use App\Enums\LeadPriority;
use App\Enums\MeetingLocationType;
use App\Support\MeetingReminderOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape validation for creating / editing a meeting. Authorization, lead
 * access, host / participant scope, conflicts and past-time rules live in
 * MeetingPolicy and the meeting services. Number, status, team and audit
 * columns are never accepted from the client.
 */
class MeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'lead_id' => ['nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:191'],
            'meeting_type_id' => [Rule::requiredIf($creating), 'integer'],
            'host_user_id' => ['nullable', 'integer'],
            'scheduled_date' => [Rule::requiredIf($creating), 'date_format:Y-m-d'],
            'start_time' => [Rule::requiredIf($creating), 'date_format:H:i'],
            'end_time' => [Rule::requiredIf($creating), 'date_format:H:i'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
            'timezone' => ['nullable', 'timezone:all'],
            'location_type' => ['nullable', Rule::enum(MeetingLocationType::class)],
            'location' => ['nullable', 'string', 'max:191'],
            'address' => ['nullable', 'string', 'max:500'],
            'meeting_url' => ['nullable', 'url:http,https', 'max:500'],
            'agenda' => ['nullable', 'string', 'max:5000'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['nullable', Rule::enum(LeadPriority::class)],
            'reminders' => ['nullable', 'array', 'max:6'],
            'reminders.*' => ['integer', Rule::in(array_keys(MeetingReminderOptions::OPTIONS))],
            'participant_user_ids' => ['nullable', 'array', 'max:25'],
            'participant_user_ids.*' => ['integer'],
            'include_lead' => ['boolean'],
            'external_participants' => ['nullable', 'array', 'max:25'],
            'external_participants.*.name' => ['required', 'string', 'max:191'],
            'external_participants.*.email' => ['nullable', 'email', 'max:191'],
            'external_participants.*.phone' => ['nullable', 'string', 'max:30'],
            'override_conflict' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'meeting_type_id' => 'meeting type',
            'scheduled_date' => 'date',
            'meeting_url' => 'online meeting URL',
            'external_participants.*.name' => 'participant name',
            'external_participants.*.email' => 'participant email',
        ];
    }

    /** Data for MeetingService (schedule and participants are ignored on update). */
    public function payload(): array
    {
        $data = collect($this->validated())->except(['lead_id', 'override_conflict']);

        if (! $this->isMethod('post')) {
            $data = $data->except(['scheduled_date', 'start_time', 'end_time', 'end_date', 'timezone', 'participant_user_ids', 'include_lead', 'external_participants']);
        }

        return $data->all();
    }
}
