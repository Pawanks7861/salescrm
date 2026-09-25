<?php

namespace App\Services\Meetings;

use App\Enums\AuditAction;
use App\Models\Meeting;
use App\Models\MeetingType;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Create / edit / reorder / delete meeting types. Types in use are deactivated, never deleted. */
class MeetingTypeService
{
    public const ICONS = ['building', 'briefcase', 'video', 'phone', 'map-pin', 'presentation', 'users', 'calendar', 'chat', 'globe'];

    public function __construct(private readonly AuditService $audit) {}

    public function save(MeetingType $type, array $data): MeetingType
    {
        return DB::transaction(function () use ($type, $data) {
            $isNew = ! $type->exists;
            $type->fill($data);

            if ($isNew) {
                $type->slug = $this->uniqueSlug((string) $type->name);
                $type->sort_order ??= (int) MeetingType::max('sort_order') + 10;
            }

            [$old, $new] = $this->audit->dirtyDiff($type);
            $type->save();

            if ($isNew || $new !== []) {
                $this->audit->log(
                    $isNew ? AuditAction::ConfigurationCreated : AuditAction::ConfigurationUpdated,
                    'meeting_settings',
                    $type,
                    "Meeting type \"{$type->name}\" ".($isNew ? 'created' : 'updated'),
                    $isNew ? null : $old,
                    $new,
                );
            }

            return $type;
        });
    }

    /** @throws ValidationException */
    public function delete(MeetingType $type): void
    {
        if ($type->is_system) {
            throw ValidationException::withMessages(['record' => 'System meeting types cannot be deleted; deactivate them instead.']);
        }

        if ($this->inUse($type)) {
            throw ValidationException::withMessages(['record' => 'This type is used by existing meetings. Deactivate it instead.']);
        }

        DB::transaction(function () use ($type) {
            $type->delete();
            $this->audit->log(AuditAction::ConfigurationDeleted, 'meeting_settings', $type, "Meeting type \"{$type->name}\" deleted");
        });
    }

    /** @param array<int> $orderedIds */
    public function reorder(array $orderedIds): void
    {
        DB::transaction(function () use ($orderedIds) {
            foreach (array_values($orderedIds) as $position => $id) {
                MeetingType::query()->whereKey($id)->update(['sort_order' => ($position + 1) * 10]);
            }

            $this->audit->log(AuditAction::ConfigurationUpdated, 'meeting_settings', null, 'Meeting type order changed', null, ['order' => array_values($orderedIds)]);
        });
    }

    public function inUse(MeetingType $type): bool
    {
        return Meeting::withTrashed()->where('meeting_type_id', $type->id)->exists();
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name, '_') ?: 'type';
        $slug = $base;

        for ($i = 2; MeetingType::query()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}_{$i}";
        }

        return $slug;
    }
}
