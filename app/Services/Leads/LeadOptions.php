<?php

namespace App\Services\Leads;

use App\Enums\LeadAgeBucket;
use App\Enums\LeadPriority;
use App\Models\Campaign;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\LostReason;
use App\Models\User;
use App\Support\Permissions;

/** Dropdown data for lead screens, limited to what the viewer may use. */
class LeadOptions
{
    public function __construct(private readonly LeadVisibility $visibility) {}

    public function statuses(bool $activeOnly = true): array
    {
        return LeadStatus::query()->when($activeOnly, fn ($q) => $q->active())->ordered()
            ->get(['id', 'name', 'color', 'is_won', 'is_lost', 'is_default', 'probability'])->toArray();
    }

    public function sources(): array
    {
        return LeadSource::query()->active()->ordered()->get(['id', 'name', 'color'])->toArray();
    }

    public function campaigns(): array
    {
        return Campaign::query()->active()->orderBy('name')->get(['id', 'name', 'source_id'])->toArray();
    }

    public function lostReasons(): array
    {
        return LostReason::query()->active()->ordered()->get(['id', 'name'])->toArray();
    }

    /** Users the viewer may filter by (never wider than their visibility). */
    public function filterableUsers(User $viewer): array
    {
        return $this->visibility->tier($viewer) === LeadVisibility::ALL
            ? User::query()->orderBy('name')->get(['id', 'name'])->toArray()
            : [];
    }

    /** Active users the viewer may assign leads to; empty when they cannot assign. */
    public function assignableUsers(User $viewer): array
    {
        if (! $viewer->hasAnyPermission(Permissions::LEAD_ASSIGN, Permissions::LEAD_REASSIGN)) {
            return [];
        }

        $allowed = $this->visibility->assignableUserIds($viewer);

        return User::query()->active()
            ->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))
            ->orderBy('name')
            ->get(['id', 'name', 'designation'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'designation' => $u->designation])
            ->all();
    }

    public function priorities(): array
    {
        return LeadPriority::options();
    }

    public function ageBuckets(): array
    {
        return LeadAgeBucket::options();
    }
}
