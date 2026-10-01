<?php

namespace App\Http\Requests\Batches;

use Illuminate\Foundation\Http\FormRequest;

/** Visibility of every lead id is enforced by BatchService::addLeads(). */
class AddBatchLeadsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('addLeads', $this->route('batch'));
    }

    public function rules(): array
    {
        return BatchRules::leadIds();
    }

    /** @return array<int> */
    public function leadIds(): array
    {
        return array_map('intval', $this->validated('lead_ids'));
    }
}
