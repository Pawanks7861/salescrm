<?php

namespace App\Http\Requests\Batches;

use App\Models\Batch;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Lead and trainer ids are only shape-checked here: BatchService verifies in
 * one query each that every lead is visible to the user and every trainer is
 * an active Trainer (which `exists` cannot do).
 */
class StoreBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Batch::class);
    }

    public function rules(): array
    {
        return [
            ...BatchRules::details($this->filled('start_date')),
            ...BatchRules::leadIds(required: false),
            ...BatchRules::trainerIds(),
        ];
    }

    public function messages(): array
    {
        return BatchRules::messages();
    }
}
