<?php

namespace App\Http\Controllers\Leads;

use App\Http\Controllers\Controller;
use App\Http\Presenters\LeadPresenter;
use App\Models\Lead;
use App\Services\Leads\LeadFollowupRequiredQuery;
use App\Services\Leads\LeadOptions;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Leads that need a first follow-up, or a next one after one or two completions. */
class LeadFollowupRequiredController extends Controller
{
    public function __construct(
        private readonly LeadFollowupRequiredQuery $query,
        private readonly LeadPresenter $presenter,
        private readonly LeadOptions $options,
    ) {}

    public function __invoke(Request $request): Response
    {
        $this->authorize('viewAny', Lead::class);

        $user = $request->user();
        $filters = $request->validate([
            'tab' => ['nullable', Rule::in([
                LeadFollowupRequiredQuery::TAB_ALL,
                LeadFollowupRequiredQuery::TAB_NONE,
                LeadFollowupRequiredQuery::TAB_MISSING,
            ])],
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'integer'],
            'source' => ['nullable', 'integer'],
            'campaign' => ['nullable', 'integer'],
            'assignee' => ['nullable', 'string', 'max:20'],
            'per_page' => ['nullable', 'integer', 'in:25,50,100'],
        ]);
        $filters['tab'] = $filters['tab'] ?? LeadFollowupRequiredQuery::TAB_ALL;

        $leads = $this->query->paginate($user, $filters['tab'], $filters)
            ->through(fn (Lead $lead) => [
                ...$this->presenter->row($lead, $user),
                'completed_followups_count' => (int) $lead->completed_followups_count,
                'last_followup_at' => $lead->last_completed_at
                    ? CarbonImmutable::parse($lead->last_completed_at)->utc()->toIso8601String()
                    : null,
            ]);

        return Inertia::render('Leads/FollowupRequired', [
            'leads' => $leads,
            'filters' => $filters,
            'counts' => $this->query->counts($user, $filters),
            'options' => [
                'statuses' => array_values(array_filter(
                    $this->options->statuses(),
                    fn (array $status) => ! $status['is_won'] && ! $status['is_lost'],
                )),
                'sources' => $this->options->sources(),
                'campaigns' => $this->options->campaigns(),
                'users' => $this->options->filterableUsers($user),
            ],
        ]);
    }
}
