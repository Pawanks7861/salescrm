<?php

namespace App\Services\Followups;

use App\Enums\FollowupOutcome;
use App\Enums\LeadPriority;
use App\Models\FollowupType;
use App\Models\User;
use App\Support\FollowupReminderOptions;

/** Dropdown data for follow-up screens, limited to what the viewer may use. */
class FollowupOptions
{
    public function __construct(
        private readonly FollowupVisibility $visibility,
        private readonly FollowupService $followups,
    ) {}

    /** Everything a create / edit / complete / reschedule form needs. */
    public function form(User $viewer): array
    {
        return [
            'types' => $this->types(),
            'priorities' => LeadPriority::options(),
            'outcomes' => FollowupOutcome::options(),
            'reminders' => FollowupReminderOptions::options(),
            'default_reminder' => $this->followups->defaultReminderMinutes(),
            'assignees' => $this->assignableUsers($viewer),
            'can_schedule_past' => $this->followups->canSchedulePast($viewer),
        ];
    }

    public function types(): array
    {
        return FollowupType::query()->active()->ordered()->get(['id', 'name', 'icon', 'color'])->toArray();
    }

    /** Active users the viewer may assign to; empty when they may only self-assign. */
    public function assignableUsers(User $viewer): array
    {
        $allowed = $this->visibility->assignableUserIds($viewer);
        if ($allowed === [$viewer->id]) {
            return [];
        }

        return User::query()->active()
            ->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
            ->all();
    }

    /** Users the viewer may filter the list by (never wider than their visibility). */
    public function filterableUsers(User $viewer): array
    {
        return $this->visibility->tier($viewer) === FollowupVisibility::ALL
            ? User::query()->orderBy('name')->get(['id', 'name'])->toArray()
            : [];
    }
}
