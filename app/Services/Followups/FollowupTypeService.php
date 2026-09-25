<?php

namespace App\Services\Followups;

use App\Enums\AuditAction;
use App\Models\Followup;
use App\Models\FollowupType;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Create / edit / reorder / delete follow-up types. Types in use are deactivated, never deleted. */
class FollowupTypeService
{
    public const ICONS = ['phone', 'chat', 'envelope', 'video', 'map-pin', 'calendar', 'user', 'building', 'document', 'clock', 'tag'];

    public function __construct(private readonly AuditService $audit) {}

    public function save(FollowupType $type, array $data): FollowupType
    {
        return DB::transaction(function () use ($type, $data) {
            $isNew = ! $type->exists;
            $type->fill($data);

            if ($isNew) {
                $type->slug = $this->uniqueSlug((string) $type->name);
                $type->sort_order ??= (int) FollowupType::max('sort_order') + 10;
            }

            [$old, $new] = $this->audit->dirtyDiff($type);
            $type->save();

            if ($isNew || $new !== []) {
                $this->audit->log(
                    $isNew ? AuditAction::ConfigurationCreated : AuditAction::ConfigurationUpdated,
                    'followup_settings',
                    $type,
                    "Follow-up type \"{$type->name}\" ".($isNew ? 'created' : 'updated'),
                    $isNew ? null : $old,
                    $new,
                );
            }

            return $type;
        });
    }

    /** @throws ValidationException */
    public function delete(FollowupType $type): void
    {
        if ($type->is_system) {
            throw ValidationException::withMessages(['record' => 'System follow-up types cannot be deleted; deactivate them instead.']);
        }

        if ($this->inUse($type)) {
            throw ValidationException::withMessages(['record' => 'This type is used by existing follow-ups. Deactivate it instead.']);
        }

        DB::transaction(function () use ($type) {
            $type->delete();
            $this->audit->log(AuditAction::ConfigurationDeleted, 'followup_settings', $type, "Follow-up type \"{$type->name}\" deleted");
        });
    }

    /** @param array<int> $orderedIds */
    public function reorder(array $orderedIds): void
    {
        DB::transaction(function () use ($orderedIds) {
            foreach (array_values($orderedIds) as $position => $id) {
                FollowupType::query()->whereKey($id)->update(['sort_order' => ($position + 1) * 10]);
            }

            $this->audit->log(AuditAction::ConfigurationUpdated, 'followup_settings', null, 'Follow-up type order changed', null, ['order' => array_values($orderedIds)]);
        });
    }

    public function inUse(FollowupType $type): bool
    {
        return Followup::withTrashed()->where('followup_type_id', $type->id)->exists();
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name, '_') ?: 'type';
        $slug = $base;

        for ($i = 2; FollowupType::query()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}_{$i}";
        }

        return $slug;
    }
}
