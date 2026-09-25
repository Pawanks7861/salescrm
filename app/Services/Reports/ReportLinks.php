<?php

namespace App\Services\Reports;

use App\Models\Call;
use App\Models\Followup;
use App\Models\Lead;
use App\Models\Meeting;

/**
 * Server-built drill-down URLs into the operational lists. The target list
 * re-applies its own visibility rules, so a link can never show more than the
 * user may already see there. Links are omitted when the user cannot open the
 * target module. Operational lists exclude archived leads, so drill-down
 * counts can be lower than report counts that include archived history.
 */
final class ReportLinks
{
    private array $can = [];

    public function __construct(private readonly ReportQueries $q) {}

    /** Lead list with the report's lead filters (plus overrides). */
    public function leads(array $params = [], bool $cohort = false): ?string
    {
        if (! $this->can('lead', Lead::class)) {
            return null;
        }
        $f = $this->q->filters;

        return route('leads.index', array_filter([
            'assignee' => $f->userId,
            'source' => $f->sourceId,
            'campaign' => $f->campaignId,
            'status' => $f->statusId,
            'priority' => $f->priority,
            'city' => $f->city,
            'state' => $f->state,
            'created_from' => $cohort ? $f->fromDay() : null,
            'created_to' => $cohort ? $f->toDay() : null,
            ...$params,
        ], fn ($v) => $v !== null && $v !== ''));
    }

    public function lead(int $id): ?string
    {
        return $this->can('lead', Lead::class) ? route('leads.show', $id) : null;
    }

    public function calls(array $params = [], bool $period = true): ?string
    {
        if (! $this->can('call', Call::class)) {
            return null;
        }
        $f = $this->q->filters;

        return route('calls.index', array_filter([
            'from' => $period ? $f->fromDay() : null,
            'to' => $period ? $f->toDay() : null,
            'agent' => $f->userId,
            ...$params,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []));
    }

    public function followups(array $params = [], bool $period = true): ?string
    {
        if (! $this->can('followup', Followup::class)) {
            return null;
        }
        $f = $this->q->filters;

        return route('followups.index', array_filter([
            'tab' => 'all',
            'from' => $period ? $f->fromDay() : null,
            'to' => $period ? $f->toDay() : null,
            'assigned_to' => $f->userId,
            ...$params,
        ], fn ($v) => $v !== null && $v !== ''));
    }

    public function meetings(array $params = [], bool $period = true): ?string
    {
        if (! $this->can('meeting', Meeting::class)) {
            return null;
        }
        $f = $this->q->filters;

        return route('meetings.index', array_filter([
            'tab' => 'all',
            'from' => $period ? $f->fromDay() : null,
            'to' => $period ? $f->toDay() : null,
            'host' => $f->userId,
            ...$params,
        ], fn ($v) => $v !== null && $v !== ''));
    }

    /** Link to another report page with the same filters. */
    public function report(string $slug, array $params = []): string
    {
        return route('reports.show', ['report' => $slug, ...$this->q->filters->toQuery(), ...$params]);
    }

    private function can(string $key, string $model): bool
    {
        return $this->can[$key] ??= $this->q->scope->user->can('viewAny', $model);
    }
}
