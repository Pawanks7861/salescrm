<?php

namespace App\Http\Requests\Leads;

use App\Enums\LeadPriority;
use App\Models\Lead;
use App\Services\Leads\LeadCustomFieldService;
use App\Support\LeadValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared by create and update. Ownership/status/numbering fields are not
 * accepted here (status and assignment have dedicated endpoints); assignee on
 * create is re-validated against the actor's scope by LeadAssignmentService.
 */
class LeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $lead = $this->route('lead');

        return $lead instanceof Lead
            ? $this->user()->can('update', $lead)
            : $this->user()->can('create', Lead::class);
    }

    public function rules(): array
    {
        $isCreate = ! $this->route('lead') instanceof Lead;
        $phoneRule = ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s().]+$/'];

        $rules = [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'string', 'email', 'max:191'],
            'phone' => $phoneRule,
            'alternate_phone' => $phoneRule,
            'company_name' => ['nullable', 'string', 'max:150'],
            'designation' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'pincode' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9\s-]+$/'],
            'priority' => ['required', Rule::enum(LeadPriority::class)],
            'source_id' => [$isCreate ? 'required' : 'sometimes', 'integer', Rule::exists('lead_sources', 'id')->where('is_active', true)],
            'campaign_id' => ['nullable', 'integer', Rule::exists('campaigns', 'id')],
        ];

        if (LeadValue::enabled()) {
            $rules['estimated_value'] = ['nullable', 'numeric', 'min:0', 'max:999999999999'];
        }

        if ($isCreate) {
            $rules += [
                'status_id' => ['nullable', 'integer', Rule::exists('lead_statuses', 'id')->where('is_active', true)->where('is_won', false)->where('is_lost', false)],
                'assigned_to' => ['nullable', 'integer'],
                'confirm_duplicate' => ['boolean'],
            ];
        }

        return [...$rules, ...app(LeadCustomFieldService::class)->rules()];
    }

    public function attributes(): array
    {
        return app(LeadCustomFieldService::class)->attributes();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if (blank($this->input('phone')) && blank($this->input('email'))) {
                $v->errors()->add('phone', 'Provide at least a phone number or an email address.');
            }
        });
    }
}
