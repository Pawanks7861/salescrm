<?php

namespace App\Http\Requests\Batches;

use App\Enums\BatchStatus;
use App\Services\Batches\BatchService;
use Illuminate\Validation\Rule;

/** Validation rules shared by the batch form requests. */
final class BatchRules
{
    /** Both dates are optional; the range is only checked when a start date is given. */
    public static function details(bool $hasStartDate = false): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', Rule::in([BatchStatus::Active->value, BatchStatus::Inactive->value])],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', ...($hasStartDate ? ['after_or_equal:start_date'] : [])],
        ];
    }

    public static function messages(): array
    {
        return [
            'end_date.after_or_equal' => 'The end date must be on or after the start date.',
        ];
    }

    /** Shape only: BatchService checks that every newly assigned id is an active Trainer, which `exists` cannot. */
    public static function trainerIds(bool $required = false): array
    {
        return [
            'trainer_ids' => [$required ? 'required' : 'nullable', 'array', $required ? 'min:1' : 'min:0', 'max:'.BatchService::MAX_TRAINERS_PER_REQUEST],
            'trainer_ids.*' => ['integer', 'distinct', 'exists:users,id'],
        ];
    }

    public static function leadIds(bool $required = true): array
    {
        return [
            'lead_ids' => [$required ? 'required' : 'nullable', 'array', $required ? 'min:1' : 'min:0', 'max:'.BatchService::MAX_LEADS_PER_REQUEST],
            'lead_ids.*' => ['integer', 'min:1'],
        ];
    }
}
