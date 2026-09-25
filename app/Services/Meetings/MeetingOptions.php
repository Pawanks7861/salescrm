<?php

namespace App\Services\Meetings;

use App\Enums\AttendanceStatus;
use App\Enums\LeadPriority;
use App\Enums\MeetingLocationType;
use App\Enums\MeetingOutcome;
use App\Enums\MeetingStatus;
use App\Models\MeetingType;
use App\Models\User;
use App\Support\CrmTime;
use App\Support\MeetingReminderOptions;
use App\Support\Permissions;

/** Dropdown data for meeting screens, limited to what the viewer may use. */
class MeetingOptions
{
    /** Curated timezone list for the form; the CRM timezone is always included. */
    public const TIMEZONES = [
        'Asia/Kolkata', 'Asia/Dubai', 'Asia/Singapore', 'Asia/Tokyo', 'Australia/Sydney',
        'Europe/London', 'Europe/Berlin', 'America/New_York', 'America/Chicago', 'America/Los_Angeles', 'UTC',
    ];

    public function __construct(
        private readonly MeetingVisibility $visibility,
        private readonly MeetingService $meetings,
        private readonly MeetingConflictService $conflicts,
    ) {}

    /** Everything the create / edit / reschedule / complete forms need. */
    public function form(User $viewer): array
    {
        return [
            'types' => $this->types(),
            'priorities' => LeadPriority::options(),
            'outcomes' => MeetingOutcome::options(),
            'location_types' => MeetingLocationType::options(),
            'attendance' => AttendanceStatus::options(),
            'reminders' => MeetingReminderOptions::options(),
            'default_reminders' => $this->meetings->defaultReminders(),
            'default_duration' => $this->meetings->defaultDuration(null),
            'timezones' => $this->timezones(),
            'crm_timezone' => CrmTime::tz(),
            'hosts' => $this->hostableUsers($viewer),
            'can_schedule_past' => $this->meetings->canSchedulePast($viewer),
            'can_override_conflict' => $this->conflicts->canOverride($viewer),
            'can_create_without_lead' => $viewer->hasPermission(Permissions::MEETING_CREATE_WITHOUT_LEAD),
        ];
    }

    public function types(): array
    {
        return MeetingType::query()->active()->ordered()
            ->get(['id', 'name', 'icon', 'color', 'location_mode', 'default_duration_minutes'])
            ->map(fn (MeetingType $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'icon' => $t->icon,
                'color' => $t->color,
                'location_mode' => $t->location_mode?->value,
                'default_location_type' => MeetingLocationType::forMode($t->location_mode?->value ?? 'flexible')->value,
                'default_duration_minutes' => $t->default_duration_minutes,
            ])
            ->all();
    }

    /** Active users the viewer may make host; empty when they may only host themselves. */
    public function hostableUsers(User $viewer): array
    {
        $allowed = $this->visibility->hostableUserIds($viewer);
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

    /** Users the viewer may filter by (never wider than their visibility). */
    public function filterableUsers(User $viewer): array
    {
        return $this->visibility->tier($viewer) === MeetingVisibility::ALL
            ? User::query()->orderBy('name')->get(['id', 'name'])->toArray()
            : [];
    }

    public function statuses(): array
    {
        return MeetingStatus::options();
    }

    /** @return array<int, string> */
    private function timezones(): array
    {
        return array_values(array_unique([CrmTime::tz(), ...self::TIMEZONES]));
    }
}
