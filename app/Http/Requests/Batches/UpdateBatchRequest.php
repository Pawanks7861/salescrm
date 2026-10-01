<?php

namespace App\Http\Requests\Batches;

use Illuminate\Foundation\Http\FormRequest;

/** When `trainer_ids` is sent, the batch's trainers are replaced by that list (batch.manage_trainers required). */
class UpdateBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('batch'));
    }

    public function rules(): array
    {
        return [
            ...BatchRules::details($this->filled('start_date')),
            ...BatchRules::trainerIds(),
        ];
    }

    public function messages(): array
    {
        return BatchRules::messages();
    }

    public function hasTrainerIds(): bool
    {
        return $this->has('trainer_ids');
    }

    /** @return array<int> */
    public function trainerIds(): array
    {
        return array_map('intval', $this->validated('trainer_ids') ?? []);
    }
}
