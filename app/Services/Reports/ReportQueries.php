<?php

namespace App\Services\Reports;

use App\Models\Followup;
use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\LeadEnquiry;
use App\Models\LeadStatusChange;
use App\Models\Meeting;
use Illuminate\Database\Eloquent\Builder;

/**
 * Scoped + filtered base queries. Every report service starts here, so the
 * report scope and the global filters are applied identically everywhere
 * (screen, chart, table, export, drill-down counts).
 *
 * Attribution (documented in docs/REPORTING_MODULE.md):
 *  - lead metrics        → the lead's CURRENT owner
 *  - follow-ups          → the assignee recorded on the follow-up
 *  - meetings            → the host recorded on the meeting
 */
final class ReportQueries
{
    public function __construct(
        public readonly ReportScope $scope,
        public readonly ReportFilters $filters,
    ) {}

    public function withFilters(ReportFilters $filters): self
    {
        return new self($this->scope, $filters);
    }

    public function previous(): self
    {
        return $this->withFilters($this->filters->previous());
    }

    public function leads(bool $applyStatusFilter = true): Builder
    {
        $f = $this->filters;
        $query = $this->scope->leads(Lead::query(), $f->includeArchived);

        return $this->leadAttributes($query, 'leads', $applyStatusFilter)
            ->when($f->userId, fn (Builder $q, $v) => $q->where('leads.assigned_to', $v));
    }

    /** Leads whose created_at falls in the period (the acquisition cohort). */
    public function cohort(): Builder
    {
        return $this->leads()->whereBetween('leads.created_at', [$this->filters->from, $this->filters->to]);
    }

    /** Open leads right now (never archived: a snapshot of the working pipeline). */
    public function openLeads(): Builder
    {
        return $this->withFilters($this->filters->withArchived(false))->leads()
            ->whereHas('status', fn (Builder $s) => $s->where('is_won', false)->where('is_lost', false));
    }

    /** Status changes on in-scope leads (status filter is not applied: history, not current state). */
    public function statusChanges(): Builder
    {
        return LeadStatusChange::query()
            ->whereIn('lead_status_changes.lead_id', $this->leads(false)->select('leads.id'));
    }

    public function assignments(): Builder
    {
        return LeadAssignment::query()
            ->whereIn('lead_assignments.lead_id', $this->leads(false)->select('leads.id'));
    }

    public function enquiries(): Builder
    {
        return LeadEnquiry::query()
            ->whereIn('lead_enquiries.lead_id', $this->leads()->select('leads.id'));
    }

    public function followups(): Builder
    {
        $f = $this->filters;

        return $this->activity($this->scope->followups(Followup::query(), $f->includeArchived), 'followups', nullableLead: false)
            ->when($f->userId, fn (Builder $q, $v) => $q->where('followups.assigned_to', $v));
    }

    public function meetings(): Builder
    {
        $f = $this->filters;

        return $this->activity($this->scope->meetings(Meeting::query(), $f->includeArchived), 'meetings', nullableLead: true)
            ->when($f->userId, fn (Builder $q, $v) => $q->where('meetings.host_user_id', $v));
    }

    /** Lead-attribute filters and archived handling for follow-up / meeting queries. */
    private function activity(Builder $query, string $table, bool $nullableLead): Builder
    {
        $f = $this->filters;

        if ($f->hasLeadAttributeFilters()) {
            $query->whereIn("{$table}.lead_id", $this->leadAttributes(
                $f->includeArchived ? Lead::withTrashed() : Lead::query(), 'leads', true)->select('leads.id'));
        } elseif (! $f->includeArchived) {
            $query->where(function (Builder $q) use ($table, $nullableLead) {
                $q->whereIn("{$table}.lead_id", Lead::query()->select('leads.id'));
                if ($nullableLead) {
                    $q->orWhereNull("{$table}.lead_id");
                }
            });
        }

        return $query;
    }

    private function leadAttributes(Builder $query, string $table, bool $applyStatusFilter): Builder
    {
        $f = $this->filters;

        return $query
            ->when($f->sourceId, fn (Builder $q, $v) => $q->where("{$table}.source_id", $v))
            ->when($f->campaignId, fn (Builder $q, $v) => $q->where("{$table}.campaign_id", $v))
            ->when($applyStatusFilter && $f->statusId, fn (Builder $q) => $q->where("{$table}.status_id", $f->statusId))
            ->when($f->priority, fn (Builder $q, $v) => $q->where("{$table}.priority", $v))
            ->when($f->city, fn (Builder $q, $v) => $q->where("{$table}.city", $v))
            ->when($f->state, fn (Builder $q, $v) => $q->where("{$table}.state", $v));
    }
}
