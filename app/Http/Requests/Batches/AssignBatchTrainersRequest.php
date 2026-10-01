<?php

namespace App\Http\Requests\Batches;

use Illuminate\Foundation\Http\FormRequest;

/** Trainer eligibility of every id is enforced by BatchService::assignTrainers(). */
class AssignBatchTrainersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('addTrainers', $this->route('batch'));
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
