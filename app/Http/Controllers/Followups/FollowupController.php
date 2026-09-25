<?php

namespace App\Http\Controllers\Followups;

use App\Enums\LeadPriority;
use App\Http\Controllers\Controller;
use App\Http\Presenters\FollowupPresenter;
use App\Http\Requests\Followups\FollowupRequest;
use App\Models\Followup;
use App\Models\Lead;
use App\Models\User;
use App\Services\Followups\FollowupMetrics;
use App\Services\Followups\FollowupOptions;
use App\Services\Followups\FollowupQueryService;
use App\Services\Followups\FollowupService;
use App\Services\Followups\FollowupVisibility;
use App\Services\Leads\LeadOptions;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class FollowupController extends Controller
{
    private const HISTORY_DEPTH = 20;

    public function __construct(
        private readonly FollowupService $followups,
        private readonly FollowupQueryService $queries,
        private readonly FollowupOptions $options,
        private readonly FollowupPresenter $presenter,
        private readonly FollowupVisibility $visibility,
        private readonly FollowupMetrics $metrics,
        private readonly LeadOptions $leadOptions,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Followup::class);
        $user = $request->user();

        $filters = $request->validate([
            'tab' => ['nullable', Rule::in(FollowupQueryService::TABS)],
            'search' => ['nullable', 'string', 'max:100'],
            'scope' => ['nullable', 'in:mine,all'],
            'assigned_to' => ['nullable', 'integer'],
            'type' => ['nullable', 'integer'],
            'priority' => ['nullable', Rule::enum(LeadPriority::class)],
            'lead' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'in:25,50,100'],
        ]);
        $filters['tab'] ??= 'due';

        $followups = $this->queries->filtered($user, $filters)
            ->with(FollowupPresenter::ROW_WITH)
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString()
            ->through(fn (Followup $f) => $this->presenter->row($f, $user));

        $tier = $this->visibility->tier($user);

        return Inertia::render('Followups/Index', [
            'followups' => $followups,
            'filters' => $filters,
            'counts' => $this->metrics->summary($user, ($filters['scope'] ?? null) === 'mine'),
            'options' => [
                ...$this->options->form($user),
                'users' => $this->options->filterableUsers($user),
                'statuses' => $this->leadOptions->statuses(),
                'lostReasons' => $this->leadOptions->lostReasons(),
            ],
            'can' => [
                'create' => $user->can('create', Followup::class),
                'filterByUser' => $tier !== FollowupVisibility::OWN,
                'changeLeadStatus' => $user->hasPermission(Permissions::LEAD_CHANGE_STATUS),
            ],
            'scopeLabel' => $tier === FollowupVisibility::ALL ? 'All' : 'My',
        ]);
    }

    public function show(Request $request, Followup $followup): Response
    {
        $this->authorize('view', $followup);
        $user = $request->user();

        $followup->load([...FollowupPresenter::ROW_WITH, 'creator:id,name', 'completer:id,name', 'canceller:id,name']);

        return Inertia::render('Followups/Show', [
            'followup' => [
                ...$this->presenter->detail($followup, $user),
                'deleted' => $followup->trashed(),
                'can_restore' => $user->can('restore', $followup),
            ],
            'history' => $this->history($followup, $user),
            'options' => [
                ...$this->options->form($user),
                'statuses' => $this->leadOptions->statuses(),
                'lostReasons' => $this->leadOptions->lostReasons(),
            ],
            'can' => [
                'changeLeadStatus' => $user->can('changeStatus', $followup->lead),
            ],
        ]);
    }

    public function store(FollowupRequest $request): RedirectResponse
    {
        $lead = Lead::query()->find($request->integer('lead_id'));
        abort_unless($lead, 404);
        $this->authorize('create', [Followup::class, $lead]);

        $followup = $this->followups->create($lead, $request->payload(), $request->user(), $request->boolean('confirm_duplicate'));

        return back()->with('success', "Follow-up scheduled for {$followup->assignee?->name}.");
    }

    public function update(FollowupRequest $request, Followup $followup): RedirectResponse
    {
        $this->authorize('update', $followup);

        $this->followups->update($followup, $request->payload(), $request->user());

        return back()->with('success', 'Follow-up updated.');
    }

    public function destroy(Request $request, Followup $followup): RedirectResponse
    {
        $this->authorize('delete', $followup);

        $this->followups->delete($followup, $request->user());

        return redirect()->route('followups.index')->with('success', 'Follow-up deleted.');
    }

    public function restore(Request $request, Followup $followup): RedirectResponse
    {
        $this->authorize('restore', $followup);

        $this->followups->restore($followup, $request->user());

        return redirect()->route('followups.show', $followup)->with('success', 'Follow-up restored.');
    }

    /**
     * The reschedule chain around this record (oldest first), limited to
     * entries the viewer may see.
     */
    private function history(Followup $followup, User $user): array
    {
        $chain = collect([$followup]);

        $cursor = $followup;
        for ($i = 0; $i < self::HISTORY_DEPTH && $cursor->rescheduled_from_id; $i++) {
            $cursor = Followup::withTrashed()->with(FollowupPresenter::ROW_WITH)->find($cursor->rescheduled_from_id);
            if (! $cursor) {
                break;
            }
            $chain->prepend($cursor);
        }

        $cursor = $followup;
        for ($i = 0; $i < self::HISTORY_DEPTH; $i++) {
            $cursor = Followup::withTrashed()->with(FollowupPresenter::ROW_WITH)->where('rescheduled_from_id', $cursor->id)->first();
            if (! $cursor) {
                break;
            }
            $chain->push($cursor);
        }

        return $chain
            ->filter(fn (Followup $f) => $f->is($followup) || $this->visibility->canView($user, $f))
            ->map(fn (Followup $f) => [
                'id' => $f->id,
                'current' => $f->is($followup),
                'scheduled_at' => $f->scheduled_at?->toIso8601String(),
                'status' => $f->status->value,
                'state' => $this->presenter->state($f),
                'reschedule_reason' => $f->reschedule_reason,
                'outcome' => $f->outcome?->label(),
                'assignee' => $f->assignee?->only('id', 'name'),
            ])
            ->values()
            ->all();
    }
}
