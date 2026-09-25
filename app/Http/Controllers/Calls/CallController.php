<?php

namespace App\Http\Controllers\Calls;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Http\Controllers\Controller;
use App\Http\Presenters\CallPresenter;
use App\Models\Call;
use App\Models\CallDisposition;
use App\Models\Followup;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\User;
use App\Services\Followups\FollowupOptions;
use App\Services\Leads\LeadOptions;
use App\Services\Meetings\MeetingOptions;
use App\Services\Telephony\CallQueryService;
use App\Services\Telephony\CallService;
use App\Services\Telephony\CallVisibility;
use App\Services\Telephony\TelephonyException;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CallController extends Controller
{
    public function __construct(
        private readonly CallQueryService $queries,
        private readonly CallPresenter $presenter,
        private readonly CallVisibility $visibility,
        private readonly CallService $calls,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Call::class);
        $user = $request->user();

        $filters = $request->validate([
            'today' => ['nullable', 'boolean'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'direction' => ['nullable', Rule::enum(CallDirection::class)],
            'status' => ['nullable', 'array', 'max:10'],
            'status.*' => [Rule::in(array_keys(CallQueryService::STATUS_GROUPS))],
            'agent' => ['nullable', 'integer'],
            'lead' => ['nullable', 'integer'],
            'disposition' => ['nullable', 'integer'],
            'has_recording' => ['nullable', 'boolean'],
            'missing_disposition' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'in:25,50,100'],
        ]);

        $calls = $this->queries->filtered($user, $filters)
            ->with(CallPresenter::ROW_WITH)
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString()
            ->through(fn (Call $call) => $this->presenter->row($call, $user));

        $tier = $this->visibility->tier($user);

        return Inertia::render('Calls/Index', [
            'calls' => $calls,
            'filters' => $filters,
            'counts' => $this->queries->counters($user, $tier === CallVisibility::OWN),
            'options' => [
                'statusGroups' => collect(CallQueryService::STATUS_GROUPS)->keys()->map(fn ($k) => ['value' => $k, 'label' => ucfirst(str_replace('_', ' ', $k))])->values(),
                'directions' => CallDirection::options(),
                'dispositions' => CallDisposition::query()->ordered()->get(['id', 'name', 'color']),
                'users' => $this->filterableUsers($tier),
            ],
            'can' => [
                'filterByUser' => $tier !== CallVisibility::OWN,
                'manualDial' => $user->hasPermission(Permissions::CALL_MANUAL_DIAL) && $user->hasPermission(Permissions::CALL_MAKE),
            ],
            'scopeLabel' => $tier === CallVisibility::ALL ? 'All' : 'My',
        ]);
    }

    public function show(Request $request, Call $call, LeadOptions $leadOptions, FollowupOptions $followupOptions, MeetingOptions $meetingOptions): Response
    {
        $this->authorize('view', $call);
        $user = $request->user();

        $call->load(['lead:id,lead_number,full_name,assigned_to,status_id,deleted_at', 'agent:id,name', 'disposition:id,name,color', 'dispositionBy:id,name', 'recording', 'followup:id,title,deleted_at', 'meeting:id,meeting_number,deleted_at']);

        return Inertia::render('Calls/Show', [
            'call' => $this->presenter->detail($call, $user),
            'events' => $user->can('viewEvents', $call)
                ? $call->events()->orderBy('received_at')->orderBy('id')->limit(100)->get()->map(fn ($e) => $this->presenter->event($e))->all()
                : null,
            'outcomeOptions' => $user->can('dispose', $call) ? $this->outcomeOptions($call, $user, $leadOptions, $followupOptions, $meetingOptions) : null,
        ]);
    }

    /** Starts an outbound call. The destination is resolved server-side. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lead_id' => ['nullable', 'integer'],
            'contact_field' => ['nullable', Rule::in(CallService::CONTACT_FIELDS)],
            'number' => ['nullable', 'string', 'max:30'],
            'mode' => ['nullable', Rule::in(['webrtc', 'pstn'])],
        ]);

        $lead = null;
        if (! empty($data['lead_id'])) {
            $lead = Lead::query()->find((int) $data['lead_id']);
            abort_unless($lead, 404);
        }

        try {
            ['call' => $call, 'dial' => $dial] = $this->calls->startOutbound($request->user(), $lead, $data);
        } catch (TelephonyException $e) {
            return response()->json(['message' => $e->getMessage(), 'category' => $e->category], $e->category === TelephonyException::UNAVAILABLE ? 503 : 422);
        }

        return response()->json([
            'call' => $this->live($call),
            'dial' => $dial,
        ], 201)->header('Cache-Control', 'no-store');
    }

    /** Live status for the softphone ("Finalizing call…"). */
    public function status(Request $request, Call $call): JsonResponse
    {
        $this->authorize('view', $call);

        return response()->json(['call' => $this->live($call->load('lead:id,lead_number,full_name,assigned_to,deleted_at', 'disposition:id,name'))])->header('Cache-Control', 'no-store');
    }

    /** The user's open call or most recent call awaiting disposition (restores the softphone after reload). */
    public function active(Request $request): JsonResponse
    {
        $user = $request->user();

        $open = Call::query()->visibleTo($user)
            ->where('calls.agent_user_id', $user->id)
            ->where('calls.started_at', '>=', now()->subHours(2))
            ->where(fn ($q) => $q->whereIn('calls.status', CallStatus::openValues())
                ->orWhere(fn ($w) => $w->where('calls.requires_disposition', true)->whereNull('calls.disposition_id')->where('calls.ended_at', '>=', now()->subMinutes(30))))
            ->with('lead:id,lead_number,full_name,assigned_to,deleted_at')
            ->orderByDesc('calls.started_at')
            ->first();

        return response()->json(['call' => $open ? $this->live($open) : null])->header('Cache-Control', 'no-store');
    }

    private function live(Call $call): array
    {
        return [
            'id' => $call->id,
            'call_number' => $call->call_number,
            'reference' => $call->client_reference,
            'status' => $call->status->value,
            'status_label' => $call->status->label(),
            'is_open' => $call->status->isOpen(),
            'connected' => $call->status->isConnected(),
            'channel' => $call->channel->value,
            'direction' => $call->direction->value,
            'started_at' => $call->started_at?->toIso8601String(),
            'answered_at' => $call->answered_at?->toIso8601String(),
            'ended_at' => $call->ended_at?->toIso8601String(),
            'talk_seconds' => $call->talk_duration_seconds,
            'duration' => $call->durationLabel(),
            'requires_disposition' => $call->requires_disposition && $call->disposition_id === null,
            'disposition' => $call->disposition?->name,
            'lead' => $call->lead ? ['id' => $call->lead->id, 'full_name' => $call->lead->full_name, 'lead_number' => $call->lead->lead_number] : null,
            'number' => $call->lead ? null : ($call->customer_number_normalized ? '+'.$call->customer_number_normalized : null),
            'url' => route('calls.show', $call->id, false),
        ];
    }

    /** Outcome modal data for the softphone (JSON). */
    public function outcome(Request $request, Call $call, LeadOptions $leadOptions, FollowupOptions $followupOptions, MeetingOptions $meetingOptions): JsonResponse
    {
        $this->authorize('dispose', $call);
        $user = $request->user();
        $call->load(CallPresenter::ROW_WITH);

        return response()->json([
            'call' => $this->presenter->row($call, $user),
            'options' => $this->outcomeOptions($call, $user, $leadOptions, $followupOptions, $meetingOptions),
        ])->header('Cache-Control', 'no-store');
    }

    /** Options for the outcome modal (dispositions + next action forms). */
    public function outcomeOptions(Call $call, User $user, LeadOptions $leadOptions, FollowupOptions $followupOptions, MeetingOptions $meetingOptions): array
    {
        $lead = $call->lead && ! $call->lead->trashed() ? $call->lead : null;
        $canChangeStatus = $lead && $user->hasPermission(Permissions::LEAD_CHANGE_STATUS) && $user->can('changeStatus', $lead);
        $canFollowup = $lead && $user->can('create', [Followup::class, $lead]);
        $canMeeting = $lead && $user->can('create', [Meeting::class, $lead]);

        return [
            'dispositions' => CallDisposition::query()->active()->ordered()->get(['id', 'name', 'slug', 'color', 'is_contact', 'requires_note', 'requires_next_action']),
            'statuses' => $canChangeStatus ? $leadOptions->statuses() : [],
            'lostReasons' => $canChangeStatus ? $leadOptions->lostReasons() : [],
            'followup' => $canFollowup ? $followupOptions->form($user) : null,
            'meeting' => $canMeeting ? $meetingOptions->form($user) : null,
            'can' => [
                'changeLeadStatus' => (bool) $canChangeStatus,
                'scheduleFollowup' => (bool) $canFollowup,
                'scheduleMeeting' => (bool) $canMeeting,
            ],
        ];
    }

    private function filterableUsers(string $tier): array
    {
        if ($tier !== CallVisibility::ALL) {
            return [];
        }

        return User::query()->orderBy('name')->get(['id', 'name'])->all();
    }
}
