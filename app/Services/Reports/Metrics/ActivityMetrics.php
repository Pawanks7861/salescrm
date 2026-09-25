<?php

namespace App\Services\Reports\Metrics;

use App\Enums\NoteVisibility;
use App\Models\LeadNote;
use App\Services\Reports\ReportQueries;
use Illuminate\Support\Collection;

/**
 * Work volume per person in the period (who performed the action). These are
 * plain counts for coaching context and are NOT a productivity score.
 * Only actions on records the viewer can see are counted; private /
 * management-only notes are counted only for their author.
 */
class ActivityMetrics
{
    /** @return array<string, Collection<string, int>> metric → [user_id → count] */
    public function byUser(ReportQueries $q): array
    {
        $from = $q->filters->from;
        $to = $q->filters->to;
        $pluck = fn ($query, string $col) => $query->toBase()->whereNotNull($col)
            ->selectRaw("{$col} AS k, COUNT(*) AS c")->groupBy($col)->pluck('c', 'k')->map(fn ($v) => (int) $v);

        $viewer = $q->scope->user->id;

        return [
            'leads_created' => $pluck($q->cohort(), 'leads.created_by'),
            'status_changes' => $pluck($q->statusChanges()->whereNotNull('lead_status_changes.from_status_id')
                ->whereBetween('lead_status_changes.changed_at', [$from, $to]), 'lead_status_changes.changed_by'),
            'notes' => $pluck(LeadNote::query()
                ->whereIn('lead_notes.lead_id', $q->leads(false)->select('leads.id'))
                ->where(fn ($w) => $w->where('lead_notes.visibility', NoteVisibility::Team->value)->orWhere('lead_notes.created_by', $viewer))
                ->whereBetween('lead_notes.created_at', [$from, $to]), 'lead_notes.created_by'),
            'calls' => $pluck($q->calls()->where('calls.direction', 'outbound')
                ->whereBetween('calls.started_at', [$from, $to]), 'calls.agent_user_id'),
            'followups_completed' => $pluck($q->followups()->where('followups.status', 'completed')
                ->whereBetween('followups.completed_at', [$from, $to]), 'followups.completed_by'),
            'meetings_completed' => $pluck($q->meetings()->where('meetings.status', 'completed')
                ->whereBetween('meetings.completed_at', [$from, $to]), 'meetings.completed_by'),
        ];
    }
}
