<?php

namespace App\Services\Meetings;

use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Models\User;
use App\Services\Leads\LeadQueryService;
use App\Support\CrmTime;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Visibility-scoped meeting queries. Every tab, filter and search is SQL.
 *
 *   upcoming = open (scheduled / confirmed / in progress) AND end_at >= now
 *   today    = starts today (CRM timezone), excluding rescheduled originals
 *   past     = ended before now, excluding rescheduled originals
 *   completed / cancelled / no_show = by status
 */
class MeetingQueryService
{
    public const TABS = ['upcoming', 'today', 'past', 'completed', 'cancelled', 'no_show', 'all'];

    public function __construct(private readonly LeadQueryService $leadQueries) {}

    public function base(User $user): Builder
    {
        return Meeting::query()->visibleTo($user);
    }

    /** @param array<string, mixed> $filters */
    public function filtered(User $user, array $filters): Builder
    {
        $tab = in_array($filters['tab'] ?? null, self::TABS, true) ? $filters['tab'] : 'upcoming';
        $query = $this->base($user);

        $this->applyTab($query, $tab);
        $this->search($query, (string) ($filters['search'] ?? ''));

        $status = MeetingStatus::tryFrom((string) ($filters['status'] ?? ''));

        $query
            ->when(($filters['scope'] ?? null) === 'mine', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('meetings.host_user_id', $user->id)
                ->orWhere('meetings.created_by', $user->id)
                ->orWhereHas('participants', fn (Builder $p) => $p->where('user_id', $user->id))))
            ->when($filters['host'] ?? null, fn (Builder $q, $v) => $q->where('meetings.host_user_id', (int) $v))
            ->when($filters['type'] ?? null, fn (Builder $q, $v) => $q->where('meeting_type_id', (int) $v))
            ->when($status, fn (Builder $q) => $q->where('meetings.status', $status->value))
            ->when($filters['priority'] ?? null, fn (Builder $q, $v) => $q->where('meetings.priority', (string) $v))
            ->when($filters['location_type'] ?? null, fn (Builder $q, $v) => $q->where('meetings.location_type', (string) $v))
            ->when($filters['lead'] ?? null, fn (Builder $q, $v) => $q->where('meetings.lead_id', (int) $v))
            ->when($this->validDate($filters['from'] ?? null), fn (Builder $q, $v) => $q->where('start_at', '>=', CrmTime::startOfDate($v)))
            ->when($this->validDate($filters['to'] ?? null), fn (Builder $q, $v) => $q->where('start_at', '<=', CrmTime::endOfDate($v)));

        return $this->order($query, $tab);
    }

    public function applyTab(Builder $query, string $tab): Builder
    {
        $now = now();

        return match ($tab) {
            'upcoming' => $query->whereIn('meetings.status', MeetingStatus::openValues())->where('end_at', '>=', $now),
            'today' => $query->where('meetings.status', '!=', MeetingStatus::Rescheduled->value)
                ->whereBetween('start_at', [CrmTime::startOfToday(), CrmTime::endOfToday()]),
            'past' => $query->where('meetings.status', '!=', MeetingStatus::Rescheduled->value)->where('end_at', '<', $now),
            'completed' => $query->where('meetings.status', MeetingStatus::Completed->value),
            'cancelled' => $query->where('meetings.status', MeetingStatus::Cancelled->value),
            'no_show' => $query->where('meetings.status', MeetingStatus::NoShow->value),
            default => $query,
        };
    }

    public function order(Builder $query, string $tab): Builder
    {
        return match ($tab) {
            'upcoming', 'today' => $query->orderBy('start_at')->orderBy('meetings.id'),
            default => $query->orderByDesc('start_at')->orderByDesc('meetings.id'),
        };
    }

    /** Meeting number, title, participant name, or lead name / number / phone (shared lead search). */
    public function search(Builder $query, string $term): Builder
    {
        $term = trim($term);
        if ($term === '') {
            return $query;
        }

        $like = addcslashes($term, '%_\\');

        return $query->where(fn (Builder $q) => $q
            ->where('meetings.meeting_number', 'like', $like.'%')
            ->orWhere('meetings.title', 'like', '%'.$like.'%')
            ->orWhereHas('participants', fn (Builder $p) => $p->where('name', 'like', $like.'%'))
            ->orWhereHas('lead', fn (Builder $lead) => $this->leadQueries->search($lead, $term)));
    }

    /** Visible, calendar-relevant meetings overlapping [from, to). Rescheduled originals are hidden. */
    public function range(User $user, CarbonInterface $from, CarbonInterface $to, array $filters = []): Builder
    {
        return $this->base($user)
            ->where('meetings.status', '!=', MeetingStatus::Rescheduled->value)
            ->where('start_at', '<', $to)
            ->where('end_at', '>', $from)
            ->when(($filters['scope'] ?? null) === 'mine', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('meetings.host_user_id', $user->id)
                ->orWhere('meetings.created_by', $user->id)
                ->orWhereHas('participants', fn (Builder $p) => $p->where('user_id', $user->id))))
            ->when($filters['host'] ?? null, fn (Builder $q, $v) => $q->where('meetings.host_user_id', (int) $v))
            ->when($filters['type'] ?? null, fn (Builder $q, $v) => $q->where('meeting_type_id', (int) $v))
            ->when(! empty($filters['hide_cancelled']), fn (Builder $q) => $q->where('meetings.status', '!=', MeetingStatus::Cancelled->value))
            ->orderBy('start_at')
            ->orderBy('meetings.id');
    }

    private function validDate(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && strtotime($value) !== false ? $value : null;
    }
}
