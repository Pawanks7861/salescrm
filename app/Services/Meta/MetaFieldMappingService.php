<?php

namespace App\Services\Meta;

use App\Enums\AuditAction;
use App\Models\FacebookFieldMapping;
use App\Models\FacebookForm;
use App\Models\LeadCustomField;
use App\Models\User;
use App\Services\AuditService;
use App\Services\SettingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns Meta `field_data` into allow-listed CRM input.
 *
 * - Destinations are limited to LEAD_TARGETS and active custom fields; ids,
 *   ownership, status, numbering and timestamps can never be targeted.
 * - Every answer is kept (bounded in size) for the enquiry, whether mapped,
 *   unmapped or rejected, so nothing a prospect submitted is lost.
 * - Standard Meta keys map automatically unless an explicit mapping exists.
 */
class MetaFieldMappingService
{
    /** CRM lead fields a Meta answer may be written to, with max length. */
    public const LEAD_TARGETS = [
        'full_name' => ['label' => 'Full name', 'max' => 201],
        'first_name' => ['label' => 'First name', 'max' => 100],
        'last_name' => ['label' => 'Last name', 'max' => 100],
        'email' => ['label' => 'Email', 'max' => 191],
        'phone' => ['label' => 'Phone', 'max' => 30],
        'alternate_phone' => ['label' => 'Alternate phone', 'max' => 30],
        'company_name' => ['label' => 'Company', 'max' => 191],
        'designation' => ['label' => 'Designation / job title', 'max' => 100],
        'city' => ['label' => 'City', 'max' => 100],
        'state' => ['label' => 'State', 'max' => 100],
        'country' => ['label' => 'Country', 'max' => 100],
        'pincode' => ['label' => 'PIN / ZIP code', 'max' => 20],
    ];

    /** Meta standard question keys → CRM lead field. */
    public const STANDARD_DEFAULTS = [
        'full_name' => 'full_name',
        'first_name' => 'first_name',
        'last_name' => 'last_name',
        'email' => 'email',
        'work_email' => 'email',
        'phone_number' => 'phone',
        'phone' => 'phone',
        'work_phone_number' => 'alternate_phone',
        'city' => 'city',
        'state' => 'state',
        'province' => 'state',
        'country' => 'country',
        'zip_code' => 'pincode',
        'post_code' => 'pincode',
        'company_name' => 'company_name',
        'job_title' => 'designation',
    ];

    public function __construct(
        private readonly SettingService $settings,
        private readonly AuditService $audit,
    ) {}

    /** @return array<int, array{value: string, label: string, group: string}> */
    public function targetOptions(): array
    {
        $lead = collect(self::LEAD_TARGETS)->map(fn ($t, $key) => ['value' => "lead:{$key}", 'label' => $t['label'], 'group' => 'Lead field'])->values();
        $custom = LeadCustomField::query()->active()->ordered()->get(['id', 'name'])
            ->map(fn ($f) => ['value' => "custom:{$f->id}", 'label' => $f->name, 'group' => 'Custom field']);

        return [['value' => 'none', 'label' => 'Keep in enquiry only', 'group' => ''], ...$lead->all(), ...$custom->all()];
    }

    /**
     * Mapping table for the admin screen / preview: every known question plus
     * any saved mapping, with its effective target and status.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rows(FacebookForm $form): array
    {
        $saved = $form->mappings()->with('customField:id,name,is_active,deleted_at')->get()->keyBy('meta_field');
        $questions = collect($form->questions())->keyBy('key');

        $keys = $questions->keys()->merge($saved->keys())->unique()->values();

        return $keys->map(function (string $key) use ($questions, $saved) {
            $question = $questions->get($key);
            $mapping = $saved->get($key);
            [$target, $status, $isDefault] = $this->effectiveTarget($key, $mapping);

            return [
                'meta_field' => $key,
                'label' => $question['label'] ?? $mapping?->meta_label ?? $key,
                'type' => $question['type'] ?? null,
                'target' => $target,
                'status' => $status,
                'is_default' => $isDefault,
                'in_form' => $question !== null,
            ];
        })->all();
    }

    /**
     * Saves the admin's choices. Targets are re-validated against the
     * allow-list and active custom fields; anything else is rejected.
     *
     * @param  array<string, string>  $targets  meta_field => "none" | "lead:{field}" | "custom:{id}"
     *
     * @throws ValidationException
     */
    public function save(FacebookForm $form, array $targets, User $actor): int
    {
        $labels = collect($form->questions())->pluck('label', 'key');
        $activeCustom = LeadCustomField::query()->active()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $parsed = [];
        $errors = [];

        foreach ($targets as $metaField => $target) {
            $metaField = (string) $metaField;
            if ($metaField === '' || mb_strlen($metaField) > 100) {
                continue;
            }

            [$type, $leadField, $customId] = $this->parseTarget((string) $target);

            if ($type === null
                || ($type === FacebookFieldMapping::TARGET_LEAD && ! array_key_exists((string) $leadField, self::LEAD_TARGETS))
                || ($type === FacebookFieldMapping::TARGET_CUSTOM && ! in_array($customId, $activeCustom, true))) {
                $errors["targets.{$metaField}"] = 'Choose an allowed CRM field.';

                continue;
            }

            $parsed[$metaField] = [$type, $leadField, $customId];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($form, $parsed, $labels, $actor) {
            $changes = [];
            foreach ($parsed as $metaField => [$type, $leadField, $customId]) {
                $mapping = FacebookFieldMapping::query()->where('facebook_form_id', $form->id)->where('meta_field', $metaField)->first()
                    ?? (new FacebookFieldMapping)->forceFill(['facebook_form_id' => $form->id, 'meta_field' => $metaField]);
                $before = $mapping->exists ? $this->targetKey($mapping) : null;

                $mapping->forceFill([
                    'meta_label' => $labels[$metaField] ?? $mapping->meta_label,
                    'target_type' => $type,
                    'lead_field' => $leadField,
                    'lead_custom_field_id' => $customId,
                    'updated_by' => $actor->id,
                ])->save();

                $after = $this->targetKey($mapping);
                if ($before !== $after) {
                    $changes[$metaField] = ['from' => $before, 'to' => $after];
                }
            }

            if ($changes !== []) {
                $this->audit->log(AuditAction::FacebookFieldMappingUpdated, 'integrations', $form, "Field mapping updated for form \"{$form->form_name}\"", null, ['form_id' => $form->form_id, 'changes' => $changes], $actor->id);
            }

            return count($changes);
        });
    }

    /**
     * Applies the form's mapping to a Meta `field_data` array.
     *
     * @return array{lead: array<string, string>, custom: array<string, mixed>, answers: array<string, string>, labels: array<string, string>, unmapped: array<int, string>, truncated: bool}
     */
    public function apply(?FacebookForm $form, mixed $fieldData): array
    {
        [$answers, $truncated] = $this->answers($fieldData);
        $saved = $form ? $form->mappings()->with('customField')->get()->keyBy('meta_field') : collect();
        $labels = $form ? collect($form->questions())->pluck('label', 'key')->all() : [];

        $lead = [];
        $custom = [];
        $unmapped = [];

        foreach ($answers as $key => $value) {
            [$target] = $this->effectiveTarget($key, $saved->get($key));

            if (str_starts_with($target, 'lead:')) {
                $field = substr($target, 5);
                if (! isset($lead[$field]) && ($clean = $this->leadValue($field, $value)) !== null) {
                    $lead[$field] = $clean;
                }
            } elseif (str_starts_with($target, 'custom:')) {
                $customField = $saved->get($key)?->customField;
                if ($customField && $customField->is_active && ! $customField->trashed()) {
                    $custom[$customField->slug] = $value;
                } else {
                    $unmapped[] = $key;
                }
            } else {
                $unmapped[] = $key;
            }
        }

        return [
            'lead' => $this->names($lead),
            'custom' => $custom,
            'answers' => $answers,
            'labels' => collect($answers)->keys()->mapWithKeys(fn ($k) => [$k => mb_substr((string) ($labels[$k] ?? $k), 0, 191)])->all(),
            'unmapped' => $unmapped,
            'truncated' => $truncated,
        ];
    }

    /**
     * Normalises untrusted field_data into [key => value] with bounded sizes.
     *
     * @return array{0: array<string, string>, 1: bool}
     */
    public function answers(mixed $fieldData): array
    {
        $maxFields = (int) config('meta.max_fields', 100);
        $maxLength = (int) config('meta.max_answer_length', 1000);
        $answers = [];
        $truncated = false;

        foreach (is_array($fieldData) ? $fieldData : [] as $item) {
            if (count($answers) >= $maxFields) {
                $truncated = true;
                break;
            }
            if (! is_array($item) || ! is_string($item['name'] ?? null)) {
                continue;
            }

            $key = mb_substr(trim($item['name']), 0, 100);
            if ($key === '' || array_key_exists($key, $answers)) {
                continue;
            }

            $values = array_filter(array_map(
                fn ($v) => is_scalar($v) ? (string) $v : null,
                is_array($item['values'] ?? null) ? array_slice($item['values'], 0, 50) : [$item['values'] ?? null],
            ), fn ($v) => $v !== null);

            $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', implode(', ', $values)) ?? '');
            if (mb_strlen($value) > $maxLength) {
                $value = mb_substr($value, 0, $maxLength);
                $truncated = true;
            }

            $answers[$key] = $value;
        }

        return [$answers, $truncated];
    }

    /** @return array{0: string, 1: string, 2: bool} [target, status, isDefault] */
    private function effectiveTarget(string $key, ?FacebookFieldMapping $mapping): array
    {
        if ($mapping) {
            if ($mapping->target_type === FacebookFieldMapping::TARGET_CUSTOM) {
                $field = $mapping->customField;
                $valid = $field && $field->is_active && ! $field->trashed();

                return ["custom:{$mapping->lead_custom_field_id}", $valid ? 'mapped' : 'invalid', false];
            }
            if ($mapping->target_type === FacebookFieldMapping::TARGET_LEAD) {
                $valid = array_key_exists((string) $mapping->lead_field, self::LEAD_TARGETS);

                return ["lead:{$mapping->lead_field}", $valid ? 'mapped' : 'invalid', false];
            }

            return ['none', 'unmapped', false];
        }

        $default = self::STANDARD_DEFAULTS[strtolower($key)] ?? null;

        return $default ? ["lead:{$default}", 'mapped', true] : ['none', 'unmapped', true];
    }

    private function targetKey(FacebookFieldMapping $mapping): string
    {
        return match ($mapping->target_type) {
            FacebookFieldMapping::TARGET_LEAD => "lead:{$mapping->lead_field}",
            FacebookFieldMapping::TARGET_CUSTOM => "custom:{$mapping->lead_custom_field_id}",
            default => 'none',
        };
    }

    /** @return array{0: ?string, 1: ?string, 2: ?int} */
    private function parseTarget(string $target): array
    {
        if ($target === 'none' || $target === '') {
            return [FacebookFieldMapping::TARGET_NONE, null, null];
        }
        if (preg_match('/^lead:([a-z_]{1,50})$/', $target, $m) === 1) {
            return [FacebookFieldMapping::TARGET_LEAD, $m[1], null];
        }
        if (preg_match('/^custom:(\d{1,10})$/', $target, $m) === 1) {
            return [FacebookFieldMapping::TARGET_CUSTOM, null, (int) $m[1]];
        }

        return [null, null, null];
    }

    private function leadValue(string $field, string $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($value === '') {
            return null;
        }

        if ($field === 'email') {
            $value = strtolower($value);

            return mb_strlen($value) <= 191 && filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
        }

        if (in_array($field, ['phone', 'alternate_phone'], true)) {
            return strlen(preg_replace('/\D+/', '', $value) ?? '') >= 6 && mb_strlen($value) <= 30 ? $value : null;
        }

        return mb_substr($value, 0, self::LEAD_TARGETS[$field]['max']);
    }

    /**
     * Name policy: explicit first/last win; otherwise a full name is split at
     * the LAST space ("Amit Kumar Desai" → "Amit Kumar" + "Desai") so the
     * recomposed full name is identical to what was submitted. With no name at
     * all the configured placeholder ("Facebook Lead") is used.
     *
     * @param  array<string, string>  $lead
     * @return array<string, string>
     */
    private function names(array $lead): array
    {
        $full = $lead['full_name'] ?? null;
        unset($lead['full_name']);

        if (empty($lead['first_name']) && $full) {
            $position = mb_strrpos($full, ' ');
            if ($position === false || ! empty($lead['last_name'])) {
                $lead['first_name'] = mb_substr($full, 0, 100);
            } else {
                $lead['first_name'] = mb_substr(mb_substr($full, 0, $position), 0, 100);
                $lead['last_name'] = mb_substr(mb_substr($full, $position + 1), 0, 100);
            }
        }

        if (empty($lead['first_name'])) {
            $lead['first_name'] = mb_substr((string) $this->settings->get('facebook.placeholder_name', 'Facebook Lead'), 0, 100) ?: 'Facebook Lead';
        }

        return $lead;
    }

    /**
     * Preview summary: counts of mapped / unmapped / invalid fields.
     *
     * @return array{mapped: int, unmapped: int, invalid: int}
     */
    public function summary(FacebookForm $form): array
    {
        $rows = collect($this->rows($form))->where('in_form', true);

        return [
            'mapped' => $rows->where('status', 'mapped')->count(),
            'unmapped' => $rows->where('status', 'unmapped')->count(),
            'invalid' => $rows->where('status', 'invalid')->count(),
        ];
    }
}
