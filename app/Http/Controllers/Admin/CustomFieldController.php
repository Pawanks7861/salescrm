<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CustomFieldType;
use App\Http\Controllers\Controller;
use App\Models\LeadCustomField;
use App\Services\Leads\LeadConfigurationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Lead custom field definitions (route-gated by lead.configure). */
class CustomFieldController extends Controller
{
    public function __construct(private readonly LeadConfigurationService $config) {}

    public function index(): Response
    {
        return Inertia::render('Admin/CustomFields/Index', [
            'fields' => LeadCustomField::query()->ordered()->get()->map(fn (LeadCustomField $f) => [
                ...$f->only('id', 'name', 'slug', 'help_text', 'is_required', 'is_active', 'sort_order'),
                'field_type' => $f->field_type->value,
                'options' => $f->options(),
                'min' => $f->validation_rules_json['min'] ?? null,
                'max' => $f->validation_rules_json['max'] ?? null,
            ]),
            'types' => array_map(fn (CustomFieldType $t) => ['value' => $t->value, 'label' => $t->label(), 'has_options' => $t->hasOptions()], CustomFieldType::cases()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $field = $this->config->save(new LeadCustomField, $this->validated($request, null));

        return back()->with('success', "Custom field \"{$field->name}\" created.");
    }

    public function update(Request $request, LeadCustomField $customField): RedirectResponse
    {
        $this->config->save($customField, $this->validated($request, $customField));

        return back()->with('success', "Custom field \"{$customField->name}\" updated.");
    }

    public function destroy(LeadCustomField $customField): RedirectResponse
    {
        $this->config->delete($customField);

        return back()->with('success', "Custom field \"{$customField->name}\" deleted.");
    }

    public function reorder(Request $request): RedirectResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array', 'max:200'], 'ids.*' => ['integer', Rule::exists('lead_custom_fields', 'id')]])['ids'];
        $this->config->reorder(LeadCustomField::class, $ids);

        return back()->with('success', 'Order saved.');
    }

    private function validated(Request $request, ?LeadCustomField $field): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('lead_custom_fields', 'name')->ignore($field?->id)->whereNull('deleted_at')],
            'field_type' => [$field ? 'prohibited' : 'required', Rule::enum(CustomFieldType::class)],
            'options' => ['nullable', 'array', 'max:100'],
            'options.*' => ['string', 'max:100', 'distinct'],
            'help_text' => ['nullable', 'string', 'max:255'],
            'is_required' => ['boolean'],
            'is_active' => ['boolean'],
            'min' => ['nullable', 'numeric'],
            'max' => ['nullable', 'numeric', 'gte:min'],
            'sort_order' => ['nullable', 'integer', 'between:0,65000'],
        ]);

        $type = $field?->field_type ?? CustomFieldType::from($data['field_type']);
        $options = array_values(array_filter(array_map('trim', $data['options'] ?? []), fn ($o) => $o !== ''));

        if ($type->hasOptions() && $options === []) {
            throw ValidationException::withMessages(['options' => 'Add at least one option.']);
        }

        $rules = array_filter(['min' => $data['min'] ?? null, 'max' => $data['max'] ?? null], fn ($v) => $v !== null);

        return array_filter([
            'name' => $data['name'],
            'field_type' => $field ? null : $type,
            'options_json' => $type->hasOptions() ? $options : null,
            'validation_rules_json' => $rules ?: null,
            'help_text' => $data['help_text'] ?? null,
            'is_required' => (bool) ($data['is_required'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'sort_order' => $data['sort_order'] ?? $field?->sort_order ?? ((int) LeadCustomField::max('sort_order') + 10),
        ], fn ($v, $k) => $v !== null || in_array($k, ['options_json', 'validation_rules_json', 'help_text'], true), ARRAY_FILTER_USE_BOTH);
    }
}
