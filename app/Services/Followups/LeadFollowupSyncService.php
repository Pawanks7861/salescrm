<?php

namespace App\Services\Followups;

use App\Models\Followup;
use App\Models\Lead;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the denormalised follow-up columns on `leads` correct.
 *
 * next_followup_at = earliest scheduled_at of the lead's PENDING, non-deleted
 * follow-ups — including overdue ones (it may be in the past: the team still
 * owes that action). NULL when nothing is pending.
 *
 * Writes go through the query builder so `leads.updated_at` is not touched
 * and no lead audit entry is produced for a derived value.
 */
class LeadFollowupSyncService
{
    public function sync(Lead|int $lead): void
    {
        $leadId = $lead instanceof Lead ? $lead->id : $lead;

        $next = Followup::query()->where('lead_id', $leadId)->pending()->min('scheduled_at');

        DB::table('leads')->where('id', $leadId)->update(['next_followup_at' => $next]);

        if ($lead instanceof Lead) {
            $lead->setAttribute('next_followup_at', $next);
            $lead->syncOriginalAttribute('next_followup_at');
        }
    }

    /** Records a real contact; never moves last_contacted_at backwards. */
    public function markContacted(Lead $lead, CarbonInterface $at): void
    {
        DB::table('leads')
            ->where('id', $lead->id)
            ->where(fn ($q) => $q->whereNull('last_contacted_at')->orWhere('last_contacted_at', '<', $at))
            ->update(['last_contacted_at' => $at]);

        if ($lead->last_contacted_at === null || $lead->last_contacted_at->lt($at)) {
            $lead->setAttribute('last_contacted_at', $at);
            $lead->syncOriginalAttribute('last_contacted_at');
        }
    }
}
