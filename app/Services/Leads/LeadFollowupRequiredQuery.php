<?php

namespace App\Services\Leads;

use App\Enums\FollowupStatus;
use App\Http\Presenters\LeadPresenter;
use App\Models\User;
use App\Support\CrmTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Leads that still need a follow-up, from the CRM-local cutoff onward.
 *
 * Category A and category B cannot overlap: A has no follow-up rows at all,
 * B has one or two completed follow-ups. Dashboard and page counts both come
 * from these queries.
 */
class LeadFollowupRequiredQuery
{
    public const TAB_ALL = 'all';

    public const TAB_NONE = 'none';

    public const TAB_MISSING = 'missing';

    public function __construct(private readonly LeadQueryService $leads) {}

    /** First CRM-local instant a lead may qualify (config cutoff, as UTC). */
    public function eligibleFrom(): CarbonImmutable
    {
        return CrmTime::startOfDate((string) config('crm.followup_required_from'));
    }

    /**
     * Visible, not archived, created on or after the cutoff, and not won or lost.
     * Soft-deleted leads are already excluded by the lead query.
     */
    public function base(User $user): Builder
    {
        return $this->leads->base($user)
            ->where('leads.created_at', '>=', $this->eligibleFrom())
            ->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('lead_statuses')
                    ->whereColumn('lead_statuses.id', 'leads.status_id')
                    ->where(function ($status): void {
                        $status->where('lead_statuses.is_won', true)
                            ->orWhere('lead_statuses.is_lost', true);
                    });
            });
    }

    /** At least five full days old and no follow-up row of any status. */
    public function withoutFollowupForFiveDays(User $user): Builder
    {
        return $this->base($user)
            ->where('leads.created_at', '<=', now()->subDays(5))
            ->whereDoesntHave('followups');
    }

    /** Exactly one or two completed follow-ups, and nothing still pending. */
    public function missingNextFollowup(User $user): Builder
    {
        return $this->base($user)
            ->whereDoesntHave('followups', fn (Builder $q) => $q->where('followups.status', FollowupStatus::Pending->value))
            ->whereRaw($this->completedCountSql().' between 1 and 2', [FollowupStatus::Completed->value]);
    }

    public function forTab(User $user, string $tab): Builder
    {
        return match ($tab) {
            self::TAB_NONE => $this->withoutFollowupForFiveDays($user),
            self::TAB_MISSING => $this->missingNextFollowup($user),
            default => $this->base($user)->where(function (Builder $query): void {
                $query->where(function (Builder $none): void {
                    $none->where('leads.created_at', '<=', now()->subDays(5))
                        ->whereDoesntHave('followups');
                })->orWhere(function (Builder $missing): void {
                    $missing->whereDoesntHave('followups', fn (Builder $q) => $q->where('followups.status', FollowupStatus::Pending->value))
                        ->whereRaw($this->completedCountSql().' between 1 and 2', [FollowupStatus::Completed->value]);
                });
            }),
        };
    }

    /**
     * Distinct totals. A and B are disjoint, so the combined total is their sum.
     *
     * @param  array<string, mixed>  $filters
     * @return array{all: int, none: int, missing: int}
     */
    public function counts(User $user, array $filters = []): array
    {
        $none = $this->applyListFilters($this->withoutFollowupForFiveDays($user), $filters)->count();
        $missing = $this->applyListFilters($this->missingNextFollowup($user), $filters)->count();

        return [
            'all' => $none + $missing,
            'none' => $none,
            'missing' => $missing,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(User $user, string $tab, array $filters): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 25);

        return $this->applyListFilters($this->forTab($user, $tab), $filters)
            ->with(LeadPresenter::ROW_WITH)
            ->withCount([
                'followups as completed_followups_count' => fn (Builder $q) => $q->where('followups.status', FollowupStatus::Completed->value),
            ])
            ->withMax([
                'followups as last_completed_at' => fn (Builder $q) => $q->where('followups.status', FollowupStatus::Completed->value),
            ], 'completed_at')
            ->orderByDesc('leads.created_at')
            ->orderByDesc('leads.id')
            ->paginate(in_array($perPage, [25, 50, 100], true) ? $perPage : 25)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function applyListFilters(Builder $query, array $filters): Builder
    {
        $this->leads->search($query, (string) ($filters['search'] ?? ''));

        $query
            ->when($filters['status'] ?? null, fn (Builder $q, $v) => $q->where('leads.status_id', (int) $v))
            ->when($filters['source'] ?? null, fn (Builder $q, $v) => $q->where('leads.source_id', (int) $v))
            ->when($filters['campaign'] ?? null, fn (Builder $q, $v) => $q->where('leads.campaign_id', (int) $v));

        $assignee = $filters['assignee'] ?? null;
        if ($assignee === 'unassigned') {
            $query->whereNull('leads.assigned_to');
        } elseif ($assignee) {
            $query->where('leads.assigned_to', (int) $assignee);
        }

        return $query;
    }

    /** Completed rows only. Cancelled, rescheduled and pending do not count. */
    private function completedCountSql(): string
    {
        return '(select count(*) from followups where followups.lead_id = leads.id and followups.deleted_at is null and followups.status = ?)';
    }
}
