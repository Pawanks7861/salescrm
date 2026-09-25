<?php

namespace App\Http\Controllers\Meetings;

use App\Enums\LeadPriority;
use App\Enums\MeetingLocationType;
use App\Enums\MeetingStatus;
use App\Http\Controllers\Controller;
use App\Http\Presenters\MeetingPresenter;
use App\Http\Requests\Meetings\MeetingRequest;
use App\Models\Followup;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\User;
use App\Services\Followups\FollowupOptions;
use App\Services\Leads\LeadOptions;
use App\Services\Meetings\MeetingMetrics;
use App\Services\Meetings\MeetingOptions;
use App\Services\Meetings\MeetingQueryService;
use App\Services\Meetings\MeetingService;
use App\Services\Meetings\MeetingVisibility;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MeetingController extends Controller
{
    private const HISTORY_DEPTH = 20;

    public function __construct(
        private readonly MeetingService $meetings,
        private readonly MeetingQueryService $queries,
        private readonly MeetingOptions $options,
        private readonly MeetingPresenter $presenter,
        private readonly MeetingVisibility $visibility,
        private readonly MeetingMetrics $metrics,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Meeting::class);
        $user = $request->user();

        $filters = $request->validate([
            'tab' => ['nullable', Rule::in(MeetingQueryService::TABS)],
            'search' => ['nullable', 'string', 'max:100'],
            'scope' => ['nullable', 'in:mine,all'],
            'host' => ['nullable', 'integer'],
            'type' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(MeetingStatus::class)],
            'priority' => ['nullable', Rule::enum(LeadPriority::class)],
            'location_type' => ['nullable', Rule::enum(MeetingLocationType::class)],
            'lead' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'in:25,50,100'],
        ]);
        $filters['tab'] ??= 'upcoming';

        $meetings = $this->queries->filtered($user, $filters)
            ->with(MeetingPresenter::ROW_WITH)
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString()
            ->through(fn (Meeting $m) => $this->presenter->row($m, $user));

        $tier = $this->visibility->tier($user);

        return Inertia::render('Meetings/Index', [
            'meetings' => $meetings,
            'filters' => $filters,
            'counts' => $this->metrics->summary($user, ($filters['scope'] ?? null) === 'mine'),
            'options' => [
                ...$this->options->form($user),
                'statuses' => $this->options->statuses(),
                'users' => $this->options->filterableUsers($user),
            ],
            'can' => [
                'create' => $user->can('create', Meeting::class),
                'createWithoutLead' => $user->can('createWithoutLead', Meeting::class),
                'filterByUser' => $tier !== MeetingVisibility::OWN,
            ],
            'scopeLabel' => $tier === MeetingVisibility::ALL ? 'All' : 'My',
        ]);
    }

    public function show(Request $request, Meeting $meeting, LeadOptions $leadOptions, FollowupOptions $followupOptions): Response
    {
        $this->authorize('view', $meeting);
        $user = $request->user();

        $meeting->load([
            'lead:id,lead_number,full_name,phone,company_name,assigned_to,deleted_at',
            'type:id,name,color,icon,location_mode',
            'host:id,name',
            'participants',
            'creator:id,name', 'completer:id,name', 'canceller:id,name',
        ]);

        $canChangeStatus = $meeting->lead && $user->hasPermission(Permissions::LEAD_CHANGE_STATUS) && $user->can('changeStatus', $meeting->lead);
        $canFollowup = $meeting->lead && $user->can('create', [Followup::class, $meeting->lead]);

        return Inertia::render('Meetings/Show', [
            'meeting' => [
                ...$this->presenter->detail($meeting, $user),
                'deleted' => $meeting->trashed(),
                'can_restore' => $user->can('restore', $meeting),
                'can_respond' => $user->can('respond', $meeting),
            ],
            'history' => $this->history($meeting, $user),
            'options' => [
                ...$this->options->form($user),
                'statuses' => $canChangeStatus ? $leadOptions->statuses() : [],
                'lostReasons' => $canChangeStatus ? $leadOptions->lostReasons() : [],
                'followup' => $canFollowup ? $followupOptions->form($user) : null,
            ],
            'can' => [
                'changeLeadStatus' => (bool) $canChangeStatus,
                'scheduleFollowup' => (bool) $canFollowup,
            ],
        ]);
    }

    public function store(MeetingRequest $request): RedirectResponse
    {
        $user = $request->user();
        $lead = null;

        if ($request->filled('lead_id')) {
            $lead = Lead::query()->find($request->integer('lead_id'));
            abort_unless($lead, 404);
            $this->authorize('create', [Meeting::class, $lead]);
        } else {
            $this->authorize('createWithoutLead', Meeting::class);
        }

        $meeting = $this->meetings->create($lead, $request->payload(), $user, $request->boolean('override_conflict'));

        return back()->with('success', "Meeting {$meeting->meeting_number} scheduled.");
    }

    public function update(MeetingRequest $request, Meeting $meeting): RedirectResponse
    {
        $this->authorize('update', $meeting);

        $this->meetings->update($meeting, $request->payload(), $request->user(), $request->boolean('override_conflict'));

        return back()->with('success', 'Meeting updated.');
    }

    public function destroy(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->authorize('delete', $meeting);

        $this->meetings->delete($meeting, $request->user());

        return redirect()->route('meetings.index')->with('success', 'Meeting deleted.');
    }

    public function restore(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->authorize('restore', $meeting);

        $this->meetings->restore($meeting, $request->user());

        return redirect()->route('meetings.show', $meeting)->with('success', 'Meeting restored.');
    }

    /** The reschedule chain around this meeting (oldest first), limited to entries the viewer may see. */
    private function history(Meeting $meeting, User $user): array
    {
        $chain = collect([$meeting]);
        $with = ['type:id,name,color', 'host:id,name', 'lead:id,assigned_to,deleted_at', 'participants:id,meeting_id,participant_type,user_id'];

        $cursor = $meeting;
        for ($i = 0; $i < self::HISTORY_DEPTH && $cursor->rescheduled_from_id; $i++) {
            $cursor = Meeting::withTrashed()->with($with)->find($cursor->rescheduled_from_id);
            if (! $cursor) {
                break;
            }
            $chain->prepend($cursor);
        }

        $cursor = $meeting;
        for ($i = 0; $i < self::HISTORY_DEPTH; $i++) {
            $cursor = Meeting::withTrashed()->with($with)->where('rescheduled_from_id', $cursor->id)->first();
            if (! $cursor) {
                break;
            }
            $chain->push($cursor);
        }

        if ($chain->count() === 1) {
            return [];
        }

        return $chain
            ->filter(fn (Meeting $m) => $m->is($meeting) || $this->visibility->canView($user, $m))
            ->map(fn (Meeting $m) => [
                'id' => $m->id,
                'meeting_number' => $m->meeting_number,
                'current' => $m->is($meeting),
                'start_at' => $m->start_at?->toIso8601String(),
                'end_at' => $m->end_at?->toIso8601String(),
                'status' => $m->status->value,
                'status_label' => $m->status->label(),
                'reschedule_reason' => $m->reschedule_reason,
                'host' => $m->host?->only('id', 'name'),
            ])
            ->values()
            ->all();
    }
}
