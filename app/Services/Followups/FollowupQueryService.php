<?php

namespace App\Services\Followups;

use App\Enums\FollowupStatus;
use App\Models\Followup;
use App\Models\User;
use App\Services\Leads\LeadQueryService;
use App\Support\CrmTime;
use Illuminate\Database\Eloquent\Builder;

/**
 * Visibility-scoped follow-up queries. Every tab and filter is SQL.
 *
 * Tabs never overlap for pending work:
 *   overdue  = pending AND scheduled_at <  now
 *   today    = pending AND now <= scheduled_at <= end of today (CRM timezone)
 *   upcoming = pending AND scheduled_at >  end of today
 *   due      = overdue + today (default landing view)
 */
class FollowupQueryService
{
    public const TABS = ['due', 'today', 'overdue', 'upcoming', 'completed', 'cancelled', 'all'];

    public function __construct(private readonly LeadQueryService $leadQueries) {}

    public function base(User $user): Builder
    {
        return Followup::query()->visibleTo($user);
    }

    /** @param array<string, mixed> $filters */
    public function filtered(User $user, array $filters): Builder
    {
        $tab = in_array($filters['tab'] ?? null, self::TABS, true) ? $filters['tab'] : 'due';
        $query = $this->base($user);

        $this->applyTab($query, $tab);
        $this->search($query, (string) ($filters['search'] ?? ''));

        $query
            ->when(($filters['scope'] ?? null) === 'mine', fn (Builder $q) => $q->where('followups.assigned_to', $user->id))
            ->when($filters['assigned_to'] ?? null, fn (Builder $q, $v) => $q->where('followups.assigned_to', (int) $v))
            ->when($filters['type'] ?? null, fn (Builder $q, $v) => $q->where('followup_type_id', (int) $v))
            ->when($filters['priority'] ?? null, fn (Builder $q, $v) => $q->where('followups.priority', (string) $v))
            ->when($filters['lead'] ?? null, fn (Builder $q, $v) => $q->where('lead_id', (int) $v))
            ->when($this->validDate($filters['from'] ?? null), fn (Builder $q, $v) => $q->where('scheduled_at', '>=', CrmTime::startOfDate($v)))
            ->when($this->validDate($filters['to'] ?? null), fn (Builder $q, $v) => $q->where('scheduled_at', '<=', CrmTime::endOfDate($v)));

        return $this->order($query, $tab);
    }

    public function applyTab(Builder $query, string $tab): Builder
    {
        $now = now();
        $endOfToday = CrmTime::endOfToday();
        $pending = FollowupStatus::Pending->value;

        return match ($tab) {
            'due' => $query->where('followups.status', $pending)->where('scheduled_at', '<=', $endOfToday),
            'today' => $query->where('followups.status', $pending)->whereBetween('scheduled_at', [$now, $endOfToday]),
            'overdue' => $query->where('followups.status', $pending)->where('scheduled_at', '<', $now),
            'upcoming' => $query->where('followups.status', $pending)->where('scheduled_at', '>', $endOfToday),
            'completed' => $query->where('followups.status', FollowupStatus::Completed->value),
            'cancelled' => $query->where('followups.status', FollowupStatus::Cancelled->value),
            default => $query,
        };
    }

    public function order(Builder $query, string $tab): Builder
    {
        return match ($tab) {
            'completed' => $query->orderByDesc('completed_at')->orderByDesc('followups.id'),
            'cancelled' => $query->orderByDesc('cancelled_at')->orderByDesc('followups.id'),
            'all' => $query->orderByDesc('scheduled_at')->orderByDesc('followups.id'),
            default => $query->orderBy('scheduled_at')->orderBy('followups.id'),
        };
    }

    /** Lead name / number / phone (via the shared lead search) or follow-up title. */
    public function search(Builder $query, string $term): Builder
    {
        $term = trim($term);
        if ($term === '') {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('followups.title', 'like', addcslashes($term, '%_\\').'%')
            ->orWhereHas('lead', fn (Builder $lead) => $this->leadQueries->search($lead, $term)));
    }

    private function validDate(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && strtotime($value) !== false ? $value : null;
    }
}
