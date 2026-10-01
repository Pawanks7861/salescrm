<?php

namespace App\Http\Requests\Batches;

use Illuminate\Foundation\Http\FormRequest;

class RemoveBatchTrainersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('removeTrainers', $this->route('batch'));
    }

    public function rules(): array
    {
        return BatchRules::trainerIds(required: true);
    }

    /** @return array<int> */
    public function trainerIds(): array
    {
        return array_map('intval', $this->validated('trainer_ids'));
    }
}
