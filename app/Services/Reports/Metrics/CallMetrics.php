<?php

namespace App\Services\Reports\Metrics;

use App\Enums\CallStatus;
use App\Services\Reports\Metric;
use App\Services\Reports\ReportLookups;
use App\Services\Reports\ReportQueries;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Call analytics from the canonical `calls` table, period = calls.started_at.
 *
 *  Connected        = status answered | completed (CallStatus::isConnected)
 *  Connection rate  = connected outbound ÷ finished outbound, where finished =
 *                     answered, completed, busy, no_answer, failed or missed
 *                     (in-flight and cancelled calls are excluded)
 *  Talk time        = provider-reported talk_duration_seconds of connected
 *                     calls only; busy / no-answer / failed never add time
 *  Call → follow-up / meeting only via the explicit calls.followup_id /
 *  calls.meeting_id links written by the call completion flow.
 */
class CallMetrics
{
    private const FINISHED = ['answered', 'completed', 'busy', 'no_answer', 'failed', 'missed'];

    public function __construct(private readonly ReportLookups $lookups) {}

    public function inPeriod(ReportQueries $q): Builder
    {
        return $q->calls()->whereBetween('calls.started_at', [$q->filters->from, $q->filters->to]);
    }

    private function aggregates(Builder $query): Builder
    {
        $connected = "'".implode("','", CallStatus::connectedValues())."'";
        $finished = "'".implode("','", self::FINISHED)."'";

        return $query
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN calls.direction = 'outbound' THEN 1 ELSE 0 END) AS outbound")
            ->selectRaw("SUM(CASE WHEN calls.direction = 'inbound' THEN 1 ELSE 0 END) AS inbound")
            ->selectRaw("SUM(CASE WHEN calls.status IN ({$connected}) THEN 1 ELSE 0 END) AS connected")
            ->selectRaw("SUM(CASE WHEN calls.direction = 'outbound' AND calls.status IN ({$connected}) THEN 1 ELSE 0 END) AS outbound_connected")
            ->selectRaw("SUM(CASE WHEN calls.direction = 'outbound' AND calls.status IN ({$finished}) THEN 1 ELSE 0 END) AS outbound_finished")
            ->selectRaw("SUM(CASE WHEN calls.direction = 'inbound' AND calls.status IN ('missed', 'no_answer') THEN 1 ELSE 0 END) AS missed_inbound")
            ->selectRaw("SUM(CASE WHEN calls.status IN ({$connected}) THEN COALESCE(calls.talk_duration_seconds, 0) ELSE 0 END) AS talk_seconds")
            ->selectRaw("SUM(CASE WHEN calls.status IN ({$connected}) AND calls.talk_duration_seconds IS NOT NULL THEN 1 ELSE 0 END) AS talk_calls")
            ->selectRaw('SUM(CASE WHEN calls.requires_disposition = 1 AND calls.disposition_id IS NULL THEN 1 ELSE 0 END) AS missing_disposition')
            ->selectRaw('SUM(CASE WHEN calls.followup_id IS NOT NULL THEN 1 ELSE 0 END) AS to_followup')
            ->selectRaw('SUM(CASE WHEN calls.meeting_id IS NOT NULL THEN 1 ELSE 0 END) AS to_meeting')
            ->selectRaw('COUNT(DISTINCT calls.lead_id) AS leads_called');
    }

    public function summary(ReportQueries $q): array
    {
        return $this->shape($this->aggregates($this->inPeriod($q))->toBase()->first());
    }

    /** @return Collection<string, array> keyed by agent id ('' = none) */
    public function by(ReportQueries $q, string $column): Collection
    {
        return $this->aggregates($this->inPeriod($q))
            ->selectRaw("{$column} AS k")
            ->groupBy($column)
            ->toBase()->get()
            ->mapWithKeys(fn ($r) => [(string) ($r->k ?? '') => $this->shape($r)]);
    }

    /** @return array<string, int> status → count */
    public function statusDistribution(ReportQueries $q): array
    {
        return $this->inPeriod($q)->toBase()->selectRaw('calls.status AS s, COUNT(*) AS c')->groupBy('calls.status')
            ->pluck('c', 's')->map(fn ($v) => (int) $v)->all();
    }

    /** @return Collection<int, object{k: ?int, c: int}> */
    public function dispositionDistribution(ReportQueries $q): Collection
    {
        return $this->inPeriod($q)->toBase()
            ->whereIn('calls.status', CallStatus::connectedValues())
            ->selectRaw('calls.disposition_id AS k, COUNT(*) AS c')->groupBy('calls.disposition_id')
            ->orderByDesc('c')->get();
    }

    /** Distinct leads called in the period that are won today (descriptive, not causal). */
    public function leadsCalledNowWon(ReportQueries $q): int
    {
        return $q->leads()
            ->whereIn('leads.id', $this->inPeriod($q)->whereNotNull('calls.lead_id')->select('calls.lead_id'))
            ->whereIn('leads.status_id', $this->lookups->wonIds())
            ->count();
    }

    private function shape(?object $r): array
    {
        $i = fn (string $k) => Metric::int($r->{$k} ?? 0);

        return [
            'total' => $i('total'),
            'outbound' => $i('outbound'),
            'inbound' => $i('inbound'),
            'connected' => $i('connected'),
            'outbound_connected' => $i('outbound_connected'),
            'outbound_finished' => $i('outbound_finished'),
            'connection_rate' => Metric::rate($i('outbound_connected'), $i('outbound_finished')),
            'missed_inbound' => $i('missed_inbound'),
            'talk_seconds' => $i('talk_seconds'),
            'avg_talk_seconds' => $i('talk_calls') ? (int) round($i('talk_seconds') / $i('talk_calls')) : null,
            'missing_disposition' => $i('missing_disposition'),
            'to_followup' => $i('to_followup'),
            'to_meeting' => $i('to_meeting'),
            'leads_called' => $i('leads_called'),
        ];
    }
}
