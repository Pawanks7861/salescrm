<?php

namespace App\Services\Reports;

use App\Models\CallDisposition;
use App\Models\Campaign;
use App\Models\FacebookForm;
use App\Models\FollowupType;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\LostReason;
use App\Models\MeetingType;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Support\Collection;

/**
 * Small reference-data maps (id → name) loaded once per request. Only used
 * to label ids that already came out of a scoped query, so they never widen
 * what a user can see.
 */
class ReportLookups
{
    private array $cache = [];

    /** @return Collection<int, LeadStatus> keyed by id, ordered by sort_order */
    public function statuses(): Collection
    {
        return $this->cache['statuses'] ??= LeadStatus::query()->orderBy('sort_order')->orderBy('id')->get()->keyBy('id');
    }

    /** Non-lost statuses in pipeline order (funnel stages). */
    public function stages(): Collection
    {
        return $this->statuses()->filter(fn (LeadStatus $s) => ! $s->is_lost && ($s->is_active || $s->is_won))->values();
    }

    /** @return array<int> */
    public function wonIds(): array
    {
        return $this->statuses()->where('is_won', true)->keys()->all() ?: [0];
    }

    /** @return array<int> */
    public function lostIds(): array
    {
        return $this->statuses()->where('is_lost', true)->keys()->all() ?: [0];
    }

    /** The status whose sort order defines "qualified" (setting report.qualified_status). */
    public function qualifiedStatus(): ?LeadStatus
    {
        $slug = (string) app(SettingService::class)->get('report.qualified_status', 'interested');

        return $this->statuses()->firstWhere('slug', $slug)
            ?? $this->statuses()->firstWhere('slug', 'interested');
    }

    public function statusName(?int $id): string
    {
        return $id ? ($this->statuses()->get($id)?->name ?? 'Unknown') : 'None';
    }

    public function users(): Collection
    {
        return $this->cache['users'] ??= User::withTrashed()->get(['id', 'name', 'is_active', 'deleted_at'])->keyBy('id');
    }

    public function userName(?int $id, string $none = 'Unassigned'): string
    {
        if (! $id) {
            return $none;
        }
        $user = $this->users()->get($id);

        return $user ? $user->name.($user->is_active && ! $user->deleted_at ? '' : ' (inactive)') : 'Unknown user';
    }

    public function sourceName(?int $id): string
    {
        $this->cache['sources'] ??= LeadSource::query()->pluck('name', 'id');

        return $id ? ($this->cache['sources'][$id] ?? 'Unknown') : 'None';
    }

    public function campaignName(?int $id): string
    {
        $this->cache['campaigns'] ??= Campaign::query()->pluck('name', 'id');

        return $id ? ($this->cache['campaigns'][$id] ?? 'Unknown') : 'No campaign';
    }

    public function lostReasonName(?int $id): string
    {
        $this->cache['lostReasons'] ??= LostReason::query()->pluck('name', 'id');

        return $id ? ($this->cache['lostReasons'][$id] ?? 'Unknown') : 'No reason recorded';
    }

    public function followupTypeName(?int $id): string
    {
        $this->cache['followupTypes'] ??= FollowupType::query()->pluck('name', 'id');

        return $id ? ($this->cache['followupTypes'][$id] ?? 'Unknown') : 'None';
    }

    public function meetingTypeName(?int $id): string
    {
        $this->cache['meetingTypes'] ??= MeetingType::query()->pluck('name', 'id');

        return $id ? ($this->cache['meetingTypes'][$id] ?? 'Unknown') : 'None';
    }

    public function dispositionName(?int $id): string
    {
        $this->cache['dispositions'] ??= CallDisposition::query()->pluck('name', 'id');

        return $id ? ($this->cache['dispositions'][$id] ?? 'Unknown') : 'No disposition';
    }

    public function formName(?string $formId): string
    {
        $this->cache['forms'] ??= FacebookForm::query()->pluck('form_name', 'form_id');

        return $formId ? ($this->cache['forms'][$formId] ?? "Form {$formId}") : 'Unknown form';
    }

    /** Filter options that are not person scoped (reference data). */
    public function referenceOptions(): array
    {
        return [
            'statuses' => $this->statuses()->map(fn (LeadStatus $s) => ['value' => $s->id, 'label' => $s->name])->values(),
            'sources' => LeadSource::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])->map(fn ($s) => ['value' => $s->id, 'label' => $s->name]),
            'campaigns' => Campaign::query()->orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['value' => $c->id, 'label' => $c->name]),
        ];
    }
}
