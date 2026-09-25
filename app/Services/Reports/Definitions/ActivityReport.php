<?php

namespace App\Services\Reports\Definitions;

use App\Services\Reports\Metrics\ActivityMetrics;
use App\Services\Reports\ReportLinks;
use App\Services\Reports\ReportLookups;
use App\Services\Reports\ReportQueries;

class ActivityReport extends ReportDefinition
{
    public const SLUG = 'activity';

    public const TITLE = 'User activity';

    public const CATEGORY = 'People';

    public const DESCRIPTION = 'Work logged per person in the period: leads created, status changes, notes, calls, follow-ups and meetings.';

    public const FILTERS = ['date', 'user', 'archived'];

    public function __construct(ReportLookups $lookups, private readonly ActivityMetrics $activity)
    {
        parent::__construct($lookups);
    }

    public function sections(ReportQueries $q, ReportLinks $links): array
    {
        $data = $this->activity->byUser($q);
        $users = $q->scope->users()
            ->when($q->filters->userId, fn ($b, $id) => $b->whereKey($id))
            ->get(['id', 'name', 'is_active']);

        $keys = array_keys($data);
        $rows = [];
        foreach ($users as $user) {
            $row = ['name' => $user->name.($user->is_active ? '' : ' (inactive)')];
            foreach ($keys as $k) {
                $row[$k] = (int) ($data[$k][$user->id] ?? 0);
            }
            $row['total'] = array_sum(array_intersect_key($row, array_flip($keys)));
            if ($row['total'] === 0 && ! $user->is_active) {
                continue;
            }
            $row['_muted'] = ! $user->is_active;
            $row['_links'] = array_filter([
                'calls' => $links->calls(['agent' => $user->id, 'direction' => 'outbound']),
            ]);
            $rows[] = $row;
        }

        $columns = [
            $this->col('name', 'Person', 'text'),
            $this->col('leads_created', 'Leads created'),
            $this->col('status_changes', 'Status changes'),
            $this->col('notes', 'Notes'),
        ];
        if ($this->canSee($q, 'call')) {
            $columns[] = $this->col('calls', 'Outbound calls');
        }
        if ($this->canSee($q, 'followup')) {
            $columns[] = $this->col('followups_completed', 'Follow-ups completed');
        }
        if ($this->canSee($q, 'meeting')) {
            $columns[] = $this->col('meetings_completed', 'Meetings completed');
        }

        return [
            $this->table('activity', 'Activity in the period', $columns, $rows, $this->totals($rows, [...$keys, 'total']), 'No users in scope.',
                'Plain counts of actions each person performed (on records you can see). This is context for coaching, not a productivity score or a ranking.'),
        ];
    }
}
