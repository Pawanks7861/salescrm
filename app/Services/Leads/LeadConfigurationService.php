<?php

namespace App\Services\Leads;

use App\Enums\AuditAction;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\LeadCustomField;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\LostReason;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Create/update/delete for lead reference data (statuses, sources, lost
 * reasons, campaigns, custom fields). Every change is audited; records in use
 * are deactivated rather than deleted.
 */
class LeadConfigurationService
{
    public const TYPES = [
        'statuses' => LeadStatus::class,
        'sources' => LeadSource::class,
        'lost-reasons' => LostReason::class,
        'campaigns' => Campaign::class,
        'custom-fields' => LeadCustomField::class,
    ];

    private const SLUGGED = [LeadStatus::class, LeadSource::class, LeadCustomField::class];

    public function __construct(private readonly AuditService $audit) {}

    public function save(Model $model, array $data, array $explicit = []): Model
    {
        return DB::transaction(function () use ($model, $data, $explicit) {
            $isNew = ! $model->exists;
            $model->fill($data);
            $model->forceFill($explicit);

            if ($isNew && in_array($model::class, self::SLUGGED, true) && ! $model->slug) {
                $model->slug = $this->uniqueSlug($model, (string) $model->name);
            }

            [$old, $new] = $this->audit->dirtyDiff($model);
            $model->save();

            if ($explicit['is_default'] ?? false) {
                $model->newQuery()->whereKeyNot($model->getKey())->where('is_default', true)->update(['is_default' => false]);
            }

            if ($isNew || $new !== []) {
                $this->audit->log(
                    $isNew ? AuditAction::ConfigurationCreated : AuditAction::ConfigurationUpdated,
                    'lead_settings',
                    $model,
                    class_basename($model).' "'.$model->name.'" '.($isNew ? 'created' : 'updated'),
                    $isNew ? null : $old,
                    $new,
                );
            }

            return $model;
        });
    }

    /** @throws ValidationException */
    public function delete(Model $model): void
    {
        if ($model->getAttribute('is_system')) {
            throw ValidationException::withMessages(['record' => 'System records cannot be deleted; deactivate them instead.']);
        }

        if ($this->inUse($model)) {
            throw ValidationException::withMessages(['record' => 'This record is in use by existing leads. Deactivate it instead.']);
        }

        DB::transaction(function () use ($model) {
            $model->delete();
            $this->audit->log(AuditAction::ConfigurationDeleted, 'lead_settings', $model, class_basename($model).' "'.$model->name.'" deleted');
        });
    }

    /** @param array<int> $orderedIds */
    public function reorder(string $modelClass, array $orderedIds): void
    {
        DB::transaction(function () use ($modelClass, $orderedIds) {
            foreach (array_values($orderedIds) as $position => $id) {
                $modelClass::query()->whereKey($id)->update(['sort_order' => ($position + 1) * 10]);
            }
        });

        $this->audit->log(AuditAction::ConfigurationUpdated, 'lead_settings', null, class_basename($modelClass).' order changed', null, ['order' => array_values($orderedIds)]);
    }

    public function inUse(Model $model): bool
    {
        return match (true) {
            $model instanceof LeadStatus => Lead::withTrashed()->where('status_id', $model->id)->exists(),
            $model instanceof LeadSource => Lead::withTrashed()->where('source_id', $model->id)->exists() || Campaign::where('source_id', $model->id)->exists(),
            $model instanceof LostReason => Lead::withTrashed()->where('lost_reason_id', $model->id)->exists(),
            $model instanceof Campaign => Lead::withTrashed()->where('campaign_id', $model->id)->exists(),
            $model instanceof LeadCustomField => $model->getKey() && DB::table('lead_custom_field_values')->where('lead_custom_field_id', $model->id)->exists(),
            default => false,
        };
    }

    private function uniqueSlug(Model $model, string $name): string
    {
        $base = Str::slug($name, '_') ?: 'item';
        $slug = $base;
        $i = 2;

        while ($model->newQueryWithoutScopes()->where('slug', $slug)->exists()) {
            $slug = "{$base}_{$i}";
            $i++;
        }

        return $slug;
    }
}
