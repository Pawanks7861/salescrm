<?php

namespace App\Services\Reports\Metrics;

use App\Enums\CallStatus;
use App\Enums\FollowupOutcome;
use App\Enums\FollowupStatus;
use App\Enums\MeetingOutcome;
use App\Enums\MeetingStatus;
use App\Services\Reports\Metric;
use App\Services\Reports\ReportQueries;
use App\Services\Reports\ReportSql;
use App\Services\SettingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Speed of response for the acquisition cohort (leads created in the period).
 *
 *  First Contact          = earliest moment the lead was actually reached:
 *                           a connected call (answered_at), a follow-up
 *                           completed with a contact outcome, or a meeting
 *                           completed with a contact outcome.
 *  First Response Attempt = earliest outreach of any result: an outbound call
 *                           (started_at), any completed follow-up, or the
 *                           first contact itself if that came first.
 *
 * Activity is counted whoever performed it; negative gaps (activity logged
 * before the lead record, e.g. imports) count as 0.
 */
class ResponseMetrics
{
    /** [key, label, min seconds (inclusive), max seconds (exclusive)] */
    public const BUCKETS = [
        ['0_5m', '0–5 min', 0, 300],
        ['5_15m', '5–15 min', 300, 900],
        ['15_30m', '15–30 min', 900, 1800],
        ['30_60m', '30–60 min', 1800, 3600],
        ['1_4h', '1–4 hours', 3600, 14400],
        ['4_24h', '4–24 hours', 14400, 86400],
        ['24h_plus', '24+ hours', 86400, null],
    ];

    public function __construct(private readonly SettingService $settings) {}

    public function targetMinutes(): int
    {
        return max(1, (int) $this->settings->get('report.response_target_minutes', 15));
    }

    /** Per-lead derived table: id, created_at, owner, attempt_s, contact_s. */
    public function perLead(ReportQueries $q, ?Builder $leads = null): QueryBuilder
    {
        $leads ??= $q->cohort();

        $connected = "'".implode("','", CallStatus::connectedValues())."'";
        $contactFollowup = "'".implode("','", array_map(fn ($o) => $o->value, array_filter(FollowupOutcome::cases(), fn ($o) => $o->countsAsContact())))."'";
        $contactMeeting = "'".implode("','", array_map(fn ($o) => $o->value, array_filter(MeetingOutcome::cases(), fn ($o) => $o->countsAsContact())))."'";
        $completed = FollowupStatus::Completed->value;
        $meetingCompleted = MeetingStatus::Completed->value;

        $outboundCall = "(SELECT MIN(rc.started_at) FROM calls rc WHERE rc.lead_id = leads.id AND rc.direction = 'outbound')";
        $anyFollowup = "(SELECT MIN(rf.completed_at) FROM followups rf WHERE rf.lead_id = leads.id AND rf.status = '{$completed}' AND rf.deleted_at IS NULL)";
        $connectedCall = "(SELECT MIN(COALESCE(rc2.answered_at, rc2.started_at)) FROM calls rc2 WHERE rc2.lead_id = leads.id AND rc2.status IN ({$connected}))";
        $contactFu = "(SELECT MIN(rf2.completed_at) FROM followups rf2 WHERE rf2.lead_id = leads.id AND rf2.status = '{$completed}' AND rf2.outcome IN ({$contactFollowup}) AND rf2.deleted_at IS NULL)";
        $contactMtg = "(SELECT MIN(rm.completed_at) FROM meetings rm WHERE rm.lead_id = leads.id AND rm.status = '{$meetingCompleted}' AND rm.outcome IN ({$contactMeeting}) AND rm.deleted_at IS NULL)";

        $firstContact = ReportSql::earliest($connectedCall, $contactFu, $contactMtg);
        $firstAttempt = ReportSql::earliest($outboundCall, $anyFollowup, $connectedCall, $contactFu, $contactMtg);

        $inner = (clone $leads)->toBase()
            ->select('leads.id', 'leads.created_at', 'leads.assigned_to')
            ->selectRaw("{$firstAttempt} AS first_attempt_at")
            ->selectRaw("{$firstContact} AS first_contact_at");

        $attempt = ReportSql::diffSeconds('i.created_at', 'i.first_attempt_at');
        $contact = ReportSql::diffSeconds('i.created_at', 'i.first_contact_at');

        return DB::query()->fromSub($inner, 'i')
            ->select('i.id', 'i.created_at', 'i.assigned_to')
            ->selectRaw("CASE WHEN i.first_attempt_at IS NULL THEN NULL WHEN {$attempt} < 0 THEN 0 ELSE {$attempt} END AS attempt_s")
            ->selectRaw("CASE WHEN i.first_contact_at IS NULL THEN NULL WHEN {$contact} < 0 THEN 0 ELSE {$contact} END AS contact_s");
    }

    /** Aggregate columns shared by summary and grouped queries. */
    private function aggregates(QueryBuilder $query): QueryBuilder
    {
        $target = $this->targetMinutes() * 60;

        return $query
            ->selectRaw('COUNT(*) AS leads')
            ->selectRaw('SUM(CASE WHEN r.attempt_s IS NOT NULL THEN 1 ELSE 0 END) AS attempted')
            ->selectRaw('SUM(CASE WHEN r.contact_s IS NOT NULL THEN 1 ELSE 0 END) AS contacted')
            ->selectRaw('AVG(r.attempt_s) AS avg_attempt')
            ->selectRaw('AVG(r.contact_s) AS avg_contact')
            ->selectRaw("SUM(CASE WHEN r.attempt_s IS NOT NULL AND r.attempt_s <= {$target} THEN 1 ELSE 0 END) AS within_target");
    }

    public function summary(ReportQueries $q): array
    {
        $per = $this->perLead($q);
        $query = $this->aggregates(DB::query()->fromSub($per, 'r'));
        foreach (self::BUCKETS as [$key, , $min, $max]) {
            $cond = $max === null ? "r.attempt_s >= {$min}" : "r.attempt_s >= {$min} AND r.attempt_s < {$max}";
            $query->selectRaw("SUM(CASE WHEN {$cond} THEN 1 ELSE 0 END) AS a_{$key}");
            $cond = $max === null ? "r.contact_s >= {$min}" : "r.contact_s >= {$min} AND r.contact_s < {$max}";
            $query->selectRaw("SUM(CASE WHEN {$cond} THEN 1 ELSE 0 END) AS c_{$key}");
        }
        $row = $query->first();

        $sub = DB::query()->fromSub($per, 'r');
        $attemptP = ReportSql::percentiles($sub, 'r.attempt_s', [50, 90]);
        $contactP = ReportSql::percentiles(DB::query()->fromSub($per, 'r'), 'r.contact_s', [50, 90]);

        $leads = Metric::int($row->leads ?? 0);
        $attempted = Metric::int($row->attempted ?? 0);
        $contacted = Metric::int($row->contacted ?? 0);

        $buckets = [];
        foreach (self::BUCKETS as [$key, $label]) {
            $buckets[] = ['key' => $key, 'label' => $label, 'attempt' => Metric::int($row->{"a_{$key}"} ?? 0), 'contact' => Metric::int($row->{"c_{$key}"} ?? 0)];
        }
        $buckets[] = ['key' => 'none', 'label' => 'No response yet', 'attempt' => $leads - $attempted, 'contact' => $leads - $contacted];

        return [
            'leads' => $leads,
            'attempted' => $attempted,
            'contacted' => $contacted,
            'no_attempt' => $leads - $attempted,
            'attempt_rate' => Metric::rate($attempted, $leads),
            'contact_rate' => Metric::rate($contacted, $leads),
            'avg_attempt' => $row->avg_attempt !== null ? round((float) $row->avg_attempt) : null,
            'avg_contact' => $row->avg_contact !== null ? round((float) $row->avg_contact) : null,
            'median_attempt' => $attemptP[50],
            'p90_attempt' => $attemptP[90],
            'median_contact' => $contactP[50],
            'p90_contact' => $contactP[90],
            'within_target' => Metric::int($row->within_target ?? 0),
            'within_target_rate' => Metric::rate($row->within_target ?? 0, $leads),
            'target_minutes' => $this->targetMinutes(),
            'buckets' => $buckets,
        ];
    }

    /** @return Collection<string, object> keyed by owner id; '' = none */
    public function by(ReportQueries $q): Collection
    {
        return $this->aggregates(DB::query()->fromSub($this->perLead($q), 'r'))
            ->selectRaw('r.assigned_to AS k')
            ->groupBy('r.assigned_to')
            ->get()
            ->keyBy(fn ($r) => (string) ($r->k ?? ''));
    }

    /**
     * Meta speed-to-lead for cohort leads that came from a Meta lead form.
     * Ingestion delay = CRM created_at − Meta created_time (from the webhook
     * event); assignment delay = first assignment to a user − created_at.
     */
    public function metaSpeed(ReportQueries $q): array
    {
        $leads = $q->cohort()->whereNotNull('leads.facebook_lead_id');
        $per = $this->perLead($q, $leads);

        $ingest = ReportSql::diffSeconds('e.meta_created_at', 'l.created_at');
        $assign = ReportSql::diffSeconds('l.created_at', '(SELECT MIN(a.created_at) FROM lead_assignments a WHERE a.lead_id = l.id AND a.to_user_id IS NOT NULL)');

        $base = DB::query()->fromSub($per, 'r')
            ->join('leads as l', 'l.id', '=', 'r.id')
            ->leftJoin('facebook_webhook_events as e', 'e.leadgen_id', '=', 'l.facebook_lead_id')
            ->select('r.attempt_s', 'r.contact_s')
            ->selectRaw("CASE WHEN e.meta_created_at IS NULL THEN NULL WHEN {$ingest} < 0 THEN 0 ELSE {$ingest} END AS ingest_s")
            ->selectRaw("CASE WHEN {$assign} < 0 THEN 0 ELSE {$assign} END AS assign_s");

        $row = DB::query()->fromSub($base, 'm')
            ->selectRaw('COUNT(*) AS leads, AVG(m.ingest_s) AS ingest, AVG(m.assign_s) AS assign_avg, AVG(m.attempt_s) AS attempt, AVG(m.contact_s) AS contact')
            ->selectRaw('SUM(CASE WHEN m.attempt_s IS NULL THEN 1 ELSE 0 END) AS no_attempt')
            ->first();

        $median = fn (string $col) => ReportSql::percentiles(DB::query()->fromSub($base, 'm'), "m.{$col}", [50])[50];
        $avg = fn ($v) => $v !== null ? round((float) $v) : null;

        return [
            'leads' => Metric::int($row->leads ?? 0),
            'no_attempt' => Metric::int($row->no_attempt ?? 0),
            'stages' => [
                ['key' => 'ingest', 'label' => 'Meta form → CRM lead (ingestion)', 'avg' => $avg($row->ingest ?? null), 'median' => $median('ingest_s')],
                ['key' => 'assign', 'label' => 'CRM lead → assigned to a salesperson', 'avg' => $avg($row->assign_avg ?? null), 'median' => $median('assign_s')],
                ['key' => 'attempt', 'label' => 'CRM lead → first response attempt', 'avg' => $avg($row->attempt ?? null), 'median' => $median('attempt_s')],
                ['key' => 'contact', 'label' => 'CRM lead → first contact', 'avg' => $avg($row->contact ?? null), 'median' => $median('contact_s')],
            ],
        ];
    }
}
