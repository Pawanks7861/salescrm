<?php

namespace App\Http\Requests\Meetings;

use App\Support\MeetingReminderOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RescheduleMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scheduled_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
            'timezone' => ['nullable', 'timezone:all'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'reminders' => ['sometimes', 'array', 'max:6'],
            'reminders.*' => ['integer', Rule::in(array_keys(MeetingReminderOptions::OPTIONS))],
            'override_conflict' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['scheduled_date' => 'date'];
    }
}
