<?php

namespace App\Services\Telephony;

use App\Enums\CallRecordingStatus;
use App\Enums\CallStatus;
use App\Models\Call;
use App\Models\User;
use App\Support\CrmTime;
use Illuminate\Database\Eloquent\Builder;

/**
 * Call list queries. Every filter runs in SQL on top of CallVisibility —
 * nothing is filtered in the browser.
 */
class CallQueryService
{
    /** Status filter groups shown as chips. */
    public const STATUS_GROUPS = [
        'answered' => [CallStatus::Answered],
        'completed' => [CallStatus::Completed],
        'missed' => [CallStatus::Missed],
        'busy' => [CallStatus::Busy],
        'no_answer' => [CallStatus::NoAnswer],
        'failed' => [CallStatus::Failed, CallStatus::Cancelled],
        'in_progress' => [CallStatus::Initiated, CallStatus::Queued, CallStatus::Dialing, CallStatus::Ringing],
    ];

    public function filtered(User $user, array $filters): Builder
    {
        $query = Call::query()->visibleTo($user);

        if (! empty($filters['today'])) {
            $query->whereBetween('calls.started_at', [CrmTime::startOfToday(), CrmTime::endOfToday()]);
        }
        if (! empty($filters['from'])) {
            $query->where('calls.started_at', '>=', CrmTime::startOfDate($filters['from']));
        }
        if (! empty($filters['to'])) {
            $query->where('calls.started_at', '<=', CrmTime::endOfDate($filters['to']));
        }
        if (! empty($filters['direction'])) {
            $query->where('calls.direction', $filters['direction']);
        }
        if (! empty($filters['status'])) {
            $statuses = collect((array) $filters['status'])
                ->flatMap(fn ($group) => self::STATUS_GROUPS[$group] ?? [])
                ->map(fn (CallStatus $s) => $s->value)
                ->unique()->values()->all();
            $query->whereIn('calls.status', $statuses ?: ['__none__']);
        }
        if (! empty($filters['agent'])) {
            $query->where('calls.agent_user_id', (int) $filters['agent']);
        }
        if (! empty($filters['lead'])) {
            $query->where('calls.lead_id', (int) $filters['lead']);
        }
        if (! empty($filters['disposition'])) {
            $query->where('calls.disposition_id', (int) $filters['disposition']);
        }
        if (isset($filters['has_recording']) && $filters['has_recording'] !== '' && $filters['has_recording'] !== null) {
            $has = fn (Builder $r) => $r->where('status', CallRecordingStatus::Available->value);
            (bool) $filters['has_recording'] ? $query->whereHas('recording', $has) : $query->whereDoesntHave('recording', $has);
        }
        if (! empty($filters['missing_disposition'])) {
            $query->where('calls.requires_disposition', true)->whereNull('calls.disposition_id');
        }
        if (! empty($filters['search'])) {
            $term = addcslashes(trim((string) $filters['search']), '%_\\');
            $query->where(fn (Builder $q) => $q
                ->where('calls.call_number', 'like', "%{$term}%")
                ->orWhereHas('lead', fn (Builder $l) => $l->where(fn (Builder $w) => $w
                    ->where('leads.full_name', 'like', "%{$term}%")
                    ->orWhere('leads.lead_number', 'like', "%{$term}%")
                    ->when(ctype_digit($term), fn (Builder $w) => $w->orWhere('leads.id', (int) $term)))));
        }

        return $query->orderByDesc('calls.started_at')->orderByDesc('calls.id');
    }

    /** Dashboard counters (visibility-scoped, one query per counter). */
    public function counters(User $user, bool $mine): array
    {
        $base = fn () => Call::query()->visibleTo($user)->when($mine, fn (Builder $q) => $q->where('calls.agent_user_id', $user->id));
        $today = [CrmTime::startOfToday(), CrmTime::endOfToday()];

        return [
            'today' => $base()->whereBetween('calls.started_at', $today)->count(),
            'connected_today' => $base()->whereBetween('calls.started_at', $today)->whereIn('calls.status', CallStatus::connectedValues())->count(),
            'missed_today' => $base()->whereBetween('calls.started_at', $today)->where('calls.status', CallStatus::Missed->value)->count(),
            'awaiting_disposition' => $base()->where('calls.requires_disposition', true)->whereNull('calls.disposition_id')->count(),
        ];
    }
}
