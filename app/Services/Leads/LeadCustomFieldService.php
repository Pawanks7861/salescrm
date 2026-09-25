<?php

namespace App\Services\Leads;

use App\Enums\AuditAction;
use App\Enums\CustomFieldType;
use App\Models\Lead;
use App\Models\LeadCustomField;
use App\Models\LeadCustomFieldValue;
use App\Services\ActivityService;
use App\Services\AuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Validates and persists admin-defined lead fields. Values are stored as text
 * (multi-select as JSON); all validation happens server-side from the field
 * definition, never from what the browser claims.
 */
class LeadCustomFieldService
{
    public function __construct(
        private readonly ActivityService $activities,
        private readonly AuditService $audit,
    ) {}

    /** @return Collection<int, LeadCustomField> */
    public function activeFields(): Collection
    {
        return LeadCustomField::query()->active()->ordered()->get();
    }

    /** Validation rules keyed as custom_fields.{slug}. */
    public function rules(?Collection $fields = null): array
    {
        $rules = ['custom_fields' => ['nullable', 'array']];

        foreach ($fields ?? $this->activeFields() as $field) {
            $key = "custom_fields.{$field->slug}";
            $base = $field->is_required
                ? [$field->field_type === CustomFieldType::Checkbox ? 'accepted' : 'required']
                : ['nullable'];
            $extra = $field->validation_rules_json ?? [];
            $min = isset($extra['min']) && is_numeric($extra['min']) ? $extra['min'] : null;
            $max = isset($extra['max']) && is_numeric($extra['max']) ? $extra['max'] : null;

            $typeRules = match ($field->field_type) {
                CustomFieldType::Text => ['string', 'max:'.min((int) ($max ?? 255), 255)],
                CustomFieldType::Textarea => ['string', 'max:'.min((int) ($max ?? 5000), 5000)],
                CustomFieldType::Number => array_filter(['numeric', $min !== null ? "min:{$min}" : null, $max !== null ? "max:{$max}" : null]),
                CustomFieldType::Date => ['date_format:Y-m-d'],
                CustomFieldType::DateTime => ['date'],
                CustomFieldType::Dropdown => [Rule::in($field->options())],
                CustomFieldType::MultiSelect => ['array'],
                CustomFieldType::Checkbox => ['boolean'],
            };

            if ($field->field_type === CustomFieldType::Text && $min !== null) {
                $typeRules[] = 'min:'.(int) $min;
            }

            $rules[$key] = [...$base, ...array_values($typeRules)];

            if ($field->field_type === CustomFieldType::MultiSelect) {
                $rules["{$key}.*"] = ['string', Rule::in($field->options())];
            }
        }

        return $rules;
    }

    /**
     * Validates values arriving from an external channel against the same
     * field definitions, one field at a time. "Required" is not enforced (a
     * genuine inbound lead is never rejected for a missing optional answer);
     * invalid values are returned separately so the caller can keep them in
     * the enquiry instead of losing them.
     *
     * @param  array<string, mixed>  $values  keyed by custom field slug
     * @return array{0: array<string, mixed>, 1: array<int, string>} [valid, rejected slugs]
     */
    public function validateInbound(array $values): array
    {
        $fields = $this->activeFields()->keyBy('slug');
        $rules = $this->rules($fields);
        $valid = [];
        $rejected = [];

        foreach ($values as $slug => $value) {
            $field = $fields->get($slug);
            if (! $field) {
                $rejected[] = (string) $slug;

                continue;
            }

            if ($field->field_type === CustomFieldType::MultiSelect && is_string($value)) {
                $value = array_values(array_filter(array_map('trim', preg_split('/[,;]/', $value) ?: [])));
            }
            if ($field->field_type === CustomFieldType::Checkbox) {
                $value = in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'y', 'on'], true);
            }

            $fieldRules = array_values(array_filter($rules["custom_fields.{$slug}"] ?? [], fn ($r) => ! in_array($r, ['required', 'accepted'], true)));
            $itemRules = $rules["custom_fields.{$slug}.*"] ?? null;
            $check = ['custom_fields' => [$slug => $value]];
            $ruleset = ["custom_fields.{$slug}" => ['nullable', ...$fieldRules]] + ($itemRules ? ["custom_fields.{$slug}.*" => $itemRules] : []);

            if (validator($check, $ruleset)->passes()) {
                $valid[$slug] = $value;
            } else {
                $rejected[] = (string) $slug;
            }
        }

        return [$valid, $rejected];
    }

    public function attributes(?Collection $fields = null): array
    {
        return ($fields ?? $this->activeFields())
            ->mapWithKeys(fn (LeadCustomField $f) => ["custom_fields.{$f->slug}" => $f->name])
            ->all();
    }

    /**
     * Persists submitted values for active fields. Unknown slugs are ignored.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [old, new] of changed values
     */
    public function save(Lead $lead, array $submitted, bool $audit = true): array
    {
        $fields = $this->activeFields();
        $existing = $lead->customFieldValues()->get()->keyBy('lead_custom_field_id');
        $old = [];
        $new = [];

        foreach ($fields as $field) {
            if (! array_key_exists($field->slug, $submitted)) {
                continue;
            }

            $value = $this->serialize($field, $submitted[$field->slug]);
            $current = $existing->get($field->id)?->value;

            if ($value === $current) {
                continue;
            }

            if ($value === null) {
                $existing->get($field->id)?->delete();
            } else {
                LeadCustomFieldValue::updateOrCreate(
                    ['lead_id' => $lead->id, 'lead_custom_field_id' => $field->id],
                    ['value' => $value],
                );
            }

            $old[$field->slug] = $current;
            $new[$field->slug] = $value;
        }

        if ($audit && $new !== []) {
            $names = $fields->whereIn('slug', array_keys($new))->pluck('name')->implode(', ');

            $this->activities->record($lead, ActivityService::CUSTOM_FIELDS_UPDATED, "Updated {$names}", ['fields' => array_keys($new)]);
            $this->audit->log(AuditAction::LeadCustomFieldUpdated, 'leads', $lead, "Custom fields updated on {$lead->lead_number}", $old, $new);
        }

        return [$old, $new];
    }

    /**
     * Field definitions with current values for display/edit.
     *
     * @return array<int, array<string, mixed>>
     */
    public function valuesFor(?Lead $lead, bool $activeOnly = true): array
    {
        $values = $lead ? $lead->customFieldValues()->get()->keyBy('lead_custom_field_id') : collect();
        $fields = $activeOnly ? $this->activeFields() : LeadCustomField::query()->ordered()->get();

        return $fields->map(function (LeadCustomField $field) use ($values) {
            $raw = $values->get($field->id)?->value;

            return [
                'id' => $field->id,
                'slug' => $field->slug,
                'name' => $field->name,
                'type' => $field->field_type->value,
                'options' => $field->options(),
                'is_required' => $field->is_required,
                'help_text' => $field->help_text,
                'value' => $this->deserialize($field, $raw),
                'display' => $this->display($field, $raw),
            ];
        })->all();
    }

    private function serialize(LeadCustomField $field, mixed $value): ?string
    {
        return match ($field->field_type) {
            CustomFieldType::Checkbox => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            CustomFieldType::MultiSelect => is_array($value) && $value !== []
                ? json_encode(array_values(array_intersect($field->options(), $value)))
                : null,
            CustomFieldType::DateTime => blank($value) ? null : Carbon::parse($value)->format('Y-m-d H:i:s'),
            default => blank($value) ? null : trim((string) $value),
        };
    }

    private function deserialize(LeadCustomField $field, ?string $raw): mixed
    {
        return match ($field->field_type) {
            CustomFieldType::Checkbox => $raw === '1',
            CustomFieldType::MultiSelect => $raw ? (json_decode($raw, true) ?: []) : [],
            CustomFieldType::DateTime => $raw ? Carbon::parse($raw)->format('Y-m-d\TH:i') : null,
            default => $raw,
        };
    }

    private function display(LeadCustomField $field, ?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return match ($field->field_type) {
            CustomFieldType::Checkbox => $raw === '1' ? 'Yes' : 'No',
            CustomFieldType::MultiSelect => implode(', ', json_decode($raw, true) ?: []),
            default => $raw,
        };
    }
}
