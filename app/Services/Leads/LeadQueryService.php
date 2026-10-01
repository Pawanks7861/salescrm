<?php

namespace App\Services\Leads;

use App\Enums\LeadAgeBucket;
use App\Models\Lead;
use App\Models\User;
use App\Support\CrmTime;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Builds visibility-scoped lead queries with SQL-side filtering and search.
 * Nothing here ever loads unscoped rows into PHP.
 */
class LeadQueryService
{
    public const SORTS = ['created_at', 'updated_at', 'lead_number', 'full_name', 'priority', 'next_followup_at'];

    public function __construct(private readonly PhoneNormalizer $phones) {}

    public function base(User $user): Builder
    {
        return Lead::query()->visibleTo($user);
    }

    public function canSeeArchived(User $user): bool
    {
        return $user->hasAnyPermission(Permissions::LEAD_DELETE, Permissions::LEAD_RESTORE);
    }

    /** @param array<string, mixed> $filters */
    public function filtered(User $user, array $filters): Builder
    {
        $query = $this->base($user);

        if (($filters['archived'] ?? null) === 'only' && $this->canSeeArchived($user)) {
            $query->onlyTrashed();
        }

        $this->search($query, (string) ($filters['search'] ?? ''));

        $query
            ->when($filters['status'] ?? null, fn (Builder $q, $v) => $q->where('status_id', (int) $v))
            ->when($filters['source'] ?? null, fn (Builder $q, $v) => $q->where('source_id', (int) $v))
            ->when($filters['campaign'] ?? null, fn (Builder $q, $v) => $q->where('campaign_id', (int) $v))
            ->when($filters['facebook_page'] ?? null, fn (Builder $q, $v) => $q->where('facebook_page_id', (string) $v))
            ->when($filters['facebook_form'] ?? null, fn (Builder $q, $v) => $q->where('facebook_form_id', (string) $v))
            ->when($filters['priority'] ?? null, fn (Builder $q, $v) => $q->where('priority', (string) $v))
            ->when($filters['city'] ?? null, fn (Builder $q, $v) => $q->where('city', 'like', $this->escape((string) $v).'%'))
            ->when($filters['state'] ?? null, fn (Builder $q, $v) => $q->where('state', 'like', $this->escape((string) $v).'%'))
            ->when(empty($filters['on']) ? ($filters['created_from'] ?? null) : null, fn (Builder $q, $v) => $q->where('created_at', '>=', $this->date($v, false)))
            ->when(empty($filters['on']) ? ($filters['created_to'] ?? null) : null, fn (Builder $q, $v) => $q->where('created_at', '<=', $this->date($v, true)))
            ->when($filters['on'] ?? null, fn (Builder $q, $v) => $q->whereBetween('created_at', [$this->date($v, false), $this->date($v, true)]))
            ->when(! empty($filters['duplicates']), fn (Builder $q) => $q->where('is_duplicate', true))
            ->when($filters['batch'] ?? null, fn (Builder $q, $v) => $this->inBatch($q, (int) $v));

        $this->nextFollowup($query, (string) ($filters['followup'] ?? ''));

        $assignee = $filters['assignee'] ?? null;
        if ($assignee === 'unassigned' || ! empty($filters['unassigned'])) {
            $query->whereNull('assigned_to');
        } elseif ($assignee) {
            $query->where('assigned_to', (int) $assignee);
        }

        if ($bucket = LeadAgeBucket::tryFrom((string) ($filters['age'] ?? ''))) {
            [$after, $onOrBefore] = $bucket->createdBetween();
            $query->where('created_at', '<=', $onOrBefore);
            if ($after) {
                $query->where('created_at', '>', $after);
            }
        }

        $sort = in_array($filters['sort'] ?? null, self::SORTS, true) ? $filters['sort'] : 'created_at';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        if ($sort === 'priority') {
            $query->orderByRaw("CASE priority WHEN 'urgent' THEN 4 WHEN 'high' THEN 3 WHEN 'medium' THEN 2 ELSE 1 END {$direction}");
        } else {
            $query->orderBy($sort, $direction);
        }

        return $query->orderBy('id', $direction);
    }

    /**
     * Lead number → prefix match; 10+ digits → exact normalised phone;
     * 4–9 digits → phone suffix match; otherwise prefix match on name/email/company.
     */
    public function search(Builder $query, string $term): Builder
    {
        $term = trim($term);
        if ($term === '') {
            return $query;
        }

        $digits = preg_replace('/\D+/', '', $term) ?? '';
        $looksLikePhone = $digits !== '' && preg_match('/^[\d\s+()\-.]+$/', $term) === 1;

        if (preg_match('/^[A-Za-z]{1,10}-\d/', $term) === 1) {
            return $query->where('lead_number', 'like', $this->escape(strtoupper($term)).'%');
        }

        if ($looksLikePhone && strlen($digits) >= 10) {
            $normalized = $this->phones->normalize($term);

            return $query->where(fn (Builder $q) => $q->whereIn('normalized_phone', array_unique([$normalized, $digits]))
                ->orWhereIn('normalized_alternate_phone', array_unique([$normalized, $digits]))
                ->orWhere('facebook_lead_id', $digits)
                ->when((string) (int) $digits === $digits, fn (Builder $q) => $q->orWhere('id', (int) $digits)));
        }

        if ($looksLikePhone && strlen($digits) >= 4) {
            return $query->where(fn (Builder $q) => $q->where('normalized_phone', 'like', '%'.$digits)
                ->orWhere('normalized_alternate_phone', 'like', '%'.$digits)
                ->when((string) (int) $digits === $digits, fn (Builder $q) => $q->orWhere('id', (int) $digits)));
        }

        if ($looksLikePhone && $digits !== '' && (string) (int) $digits === $digits) {
            return $query->where('id', (int) $digits);
        }

        $like = $this->escape($term).'%';

        return $query->where(fn (Builder $q) => $q
            ->where('full_name', 'like', $like)
            ->orWhere('last_name', 'like', $like)
            ->orWhere('email', 'like', $this->escape(strtolower($term)).'%')
            ->orWhere('company_name', 'like', $like)
            ->orWhere('lead_number', 'like', $this->escape(strtoupper($term)).'%'));
    }

    /** Batch membership only narrows a query that is already visibility-scoped. */
    public function inBatch(Builder $query, int $batchId): Builder
    {
        return $query->whereExists(fn ($sub) => $sub->from('batch_leads')
            ->whereColumn('batch_leads.lead_id', 'leads.id')
            ->where('batch_leads.batch_id', $batchId));
    }

    /** Filters on the synced leads.next_followup_at column (overdue | today | upcoming | none). */
    private function nextFollowup(Builder $query, string $value): void
    {
        match ($value) {
            'overdue' => $query->where('next_followup_at', '<', now()),
            'today' => $query->whereBetween('next_followup_at', [CrmTime::startOfToday(), CrmTime::endOfToday()]),
            'upcoming' => $query->where('next_followup_at', '>=', now()),
            'none' => $query->whereNull('next_followup_at'),
            default => null,
        };
    }

    private function escape(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    /** CRM-local calendar day boundary as UTC, so lead lists agree with report date ranges. */
    private function date(mixed $value, bool $end): Carbon
    {
        try {
            $day = Carbon::parse((string) $value)->format('Y-m-d');

            return Carbon::instance($end ? CrmTime::endOfDate($day) : CrmTime::startOfDate($day));
        } catch (\Throwable) {
            return now();
        }
    }
}
