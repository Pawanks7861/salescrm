<?php

namespace App\Http\Controllers\Leads;

use App\Enums\BatchStatus;
use App\Enums\LeadPriority;
use App\Enums\MeetingStatus;
use App\Http\Controllers\Controller;
use App\Http\Presenters\FollowupPresenter;
use App\Http\Presenters\LeadPresenter;
use App\Http\Presenters\MeetingPresenter;
use App\Http\Requests\Leads\LeadRequest;
use App\Models\Batch;
use App\Models\FacebookForm;
use App\Models\FacebookPage;
use App\Models\FacebookWebhookEvent;
use App\Models\Followup;
use App\Models\Lead;
use App\Models\LeadEnquiry;
use App\Models\LeadNote;
use App\Models\Meeting;
use App\Models\User;
use App\Services\Followups\FollowupOptions;
use App\Services\Followups\FollowupQueryService;
use App\Services\Followups\FollowupService;
use App\Services\Leads\LeadAttachmentService;
use App\Services\Leads\LeadCustomFieldService;
use App\Services\Leads\LeadNoteService;
use App\Services\Leads\LeadOptions;
use App\Services\Leads\LeadQueryService;
use App\Services\Leads\LeadService;
use App\Services\Leads\LeadVisibility;
use App\Services\Meetings\MeetingOptions;
use App\Services\Meetings\MeetingParticipantService;
use App\Services\Meetings\MeetingQueryService;
use App\Support\CrmTime;
use App\Support\LeadValue;
use App\Support\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LeadController extends Controller
{
    private const TIMELINE_PAGE_SIZE = 20;

    private const FOLLOWUP_LIMIT = 100;

    private const MEETING_LIMIT = 100;

    private const BATCH_FILTER_LIMIT = 500;

    public function __construct(
        private readonly LeadService $leads,
        private readonly LeadQueryService $queries,
        private readonly LeadOptions $options,
        private readonly LeadPresenter $presenter,
        private readonly LeadCustomFieldService $customFields,
        private readonly LeadNoteService $notes,
        private readonly LeadVisibility $visibility,
        private readonly FollowupQueryService $followupQueries,
        private readonly FollowupPresenter $followupPresenter,
        private readonly FollowupOptions $followupOptions,
        private readonly FollowupService $followupService,
        private readonly MeetingQueryService $meetingQueries,
        private readonly MeetingPresenter $meetingPresenter,
        private readonly MeetingOptions $meetingOptions,
        private readonly MeetingParticipantService $meetingParticipants,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Lead::class);
        $user = $request->user();

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'integer'],
            'source' => ['nullable', 'integer'],
            'campaign' => ['nullable', 'integer'],
            'facebook_page' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
            'facebook_form' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
            'assignee' => ['nullable', 'string', 'max:20'],
            'priority' => ['nullable', Rule::enum(LeadPriority::class)],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'created_from' => ['nullable', 'date'],
            'created_to' => ['nullable', 'date'],
            'on' => ['nullable', 'date'],
            'age' => ['nullable', 'string', 'max:10'],
            'unassigned' => ['nullable', 'boolean'],
            'duplicates' => ['nullable', 'boolean'],
            'archived' => ['nullable', 'in:only'],
            'batch' => ['nullable', 'integer'],
            'sort' => ['nullable', Rule::in(LeadQueryService::SORTS)],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'in:25,50,100'],
        ]);

        $viewBatches = $user->can('viewAny', Batch::class);
        if (! $viewBatches) {
            unset($filters['batch']);
        }

        // Default list is newest-first by created date so the UI can group rows by day.
        $filters['sort'] ??= 'created_at';
        $filters['direction'] ??= 'desc';

        $leads = $this->queries->filtered($user, $filters)
            ->with([...LeadPresenter::ROW_WITH, ...($viewBatches ? ['batches' => fn ($q) => $q->select('batches.id', 'batches.name')->orderBy('batches.name')] : [])])
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString()
            ->through(fn (Lead $lead) => [
                ...$this->presenter->row($lead, $user),
                ...($viewBatches ? ['batches' => $lead->batches->map(fn (Batch $b) => $b->only('id', 'name'))->values()] : []),
            ]);

        return Inertia::render('Leads/Index', [
            'leads' => $leads,
            'filters' => $filters,
            'options' => [
                'statuses' => $this->options->statuses(false),
                'sources' => $this->options->sources(),
                'campaigns' => $this->options->campaigns(),
                'facebookPages' => FacebookPage::query()->orderBy('page_name')->get(['page_id', 'page_name'])->unique('page_id')->values(),
                'facebookForms' => FacebookForm::query()->orderBy('form_name')->get(['form_id', 'form_name'])->unique('form_id')->values(),
                'users' => $this->options->filterableUsers($user),
                'priorities' => $this->options->priorities(),
                'ageBuckets' => $this->options->ageBuckets(),
                'today' => CarbonImmutable::now(CrmTime::tz())->toDateString(),
                'yesterday' => CarbonImmutable::now(CrmTime::tz())->subDay()->toDateString(),
                'batches' => $viewBatches
                    ? Batch::query()->orderByRaw('CASE WHEN status = ? THEN 1 ELSE 0 END', [BatchStatus::Archived->value])->orderBy('name')
                        ->limit(self::BATCH_FILTER_LIMIT)->get(['id', 'name', 'status'])
                        ->map(fn (Batch $b) => [...$b->only('id', 'name'), 'archived' => $b->isArchived()])
                    : [],
            ],
            'can' => [
                'create' => $user->can('create', Lead::class),
                'seeArchived' => $this->queries->canSeeArchived($user),
                'filterByUser' => $this->visibility->tier($user) !== LeadVisibility::OWN,
                'viewBatches' => $viewBatches,
                'addToBatch' => $viewBatches && $user->hasPermission(Permissions::BATCH_MANAGE_LEADS),
                'createBatch' => $user->can('create', Batch::class),
                'manageBatchTrainers' => $user->hasPermission(Permissions::BATCH_MANAGE_TRAINERS),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Lead::class);

        return Inertia::render('Leads/Form', $this->formProps($request->user(), null));
    }

    public function store(LeadRequest $request): RedirectResponse
    {
        $lead = $this->leads->create($request->validated(), $request->user(), $request->boolean('confirm_duplicate'));

        $target = $request->user()->can('view', $lead)
            ? redirect()->route('leads.show', $lead)
            : redirect()->route('leads.index');

        return $target->with('success', "Lead {$lead->lead_number} created.");
    }

    public function show(Request $request, Lead $lead): Response
    {
        $this->authorize('view', $lead);
        $user = $request->user();

        if (! $request->header('X-Inertia-Partial-Data')) {
            $this->leads->recordView($lead, $user);
        }

        $lead->load([
            ...LeadPresenter::ROW_WITH,
            'lostReason:id,name',
            'creator:id,name',
        ]);

        $notes = $this->notes->scopeVisible($lead->notes(), $user)
            ->with(['author:id,name', 'editor:id,name'])
            ->withCount('histories')
            ->latest('id')
            ->get()
            ->map(fn (LeadNote $note) => [
                'id' => $note->id,
                'note' => $note->note,
                'visibility' => $note->visibility->value,
                'author' => $note->author?->only('id', 'name'),
                'edited' => $note->histories_count > 0,
                'editor' => $note->histories_count > 0 ? $note->editor?->only('id', 'name') : null,
                'created_at' => $note->created_at?->toIso8601String(),
                'updated_at' => $note->updated_at?->toIso8601String(),
                'can' => [
                    'update' => $user->can('update', $note->setRelation('lead', $lead)),
                    'delete' => $user->can('delete', $note),
                ],
            ]);

        $activities = $this->notes->scopeTimeline($lead->activities(), $lead, $user)
            ->with('user:id,name')->latest('id')->limit(self::TIMELINE_PAGE_SIZE + 1)->get();

        $canViewAttachments = $user->can('viewAttachments', $lead);
        $attachments = $canViewAttachments
            ? $lead->attachments()->with('uploader:id,name')->latest('id')->get()->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->original_name,
                'mime_type' => $a->mime_type,
                'size' => $a->size,
                'uploader' => $a->uploader?->only('id', 'name'),
                'created_at' => $a->created_at?->toIso8601String(),
                'can_delete' => $user->can('deleteAttachment', [$lead, $a]),
            ])
            : [];

        $enquiries = $lead->enquiries()->with(['source:id,name', 'campaign:id,name'])->latest('received_at')->limit(50)->get()
            ->map(fn ($e) => [
                'id' => $e->id,
                'source' => $e->source?->name,
                'campaign' => $e->campaign?->name,
                'external_id' => $e->external_id,
                'data' => $e->enquiry_data_json,
                'channel' => $e->channel,
                'labels' => $e->metadata_json['labels'] ?? null,
                'meta' => $e->channel === LeadEnquiry::CHANNEL_FACEBOOK ? collect($e->metadata_json ?? [])
                    ->only(['platform', 'page_name', 'form_name', 'campaign_name', 'adset_name', 'ad_name', 'is_organic'])->all() : null,
                'is_duplicate' => $e->is_duplicate,
                'received_at' => $e->received_at?->toIso8601String(),
            ]);

        $assignments = $lead->assignments()->with(['fromUser:id,name', 'toUser:id,name', 'assigner:id,name', 'toTeam:id,name'])->latest('id')->limit(10)->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'from' => $a->fromUser?->name,
                'to' => $a->toUser?->name ?? ($a->toTeam ? $a->toTeam->name.' queue' : null),
                'by' => $a->assigner?->name,
                'type' => $a->assignment_type->value,
                'reason' => $a->reason,
                'created_at' => $a->created_at?->toIso8601String(),
            ]);

        $duplicateOf = $lead->duplicate_of_id ? $lead->duplicateOf : null;
        $showDuplicateOf = $duplicateOf && $user->can('view', $duplicateOf);

        $canCreateFollowup = ! $lead->trashed() && $user->can('create', [Followup::class, $lead]);
        $followups = $user->can('viewAny', Followup::class)
            ? $this->followupQueries->base($user)->where('lead_id', $lead->id)
                ->with([...FollowupPresenter::ROW_WITH, 'creator:id,name'])
                ->orderByRaw('CASE WHEN followups.status = ? THEN 0 ELSE 1 END', ['pending'])
                ->orderBy('scheduled_at')
                ->limit(self::FOLLOWUP_LIMIT)
                ->get()
                ->each(fn (Followup $f) => $f->setRelation('lead', $lead))
                ->map(fn (Followup $f) => [
                    ...$this->followupPresenter->row($f, $user),
                    'creator' => $f->creator?->only('id', 'name'),
                ])
            : collect();

        $canCreateMeeting = ! $lead->trashed() && $user->can('create', [Meeting::class, $lead]);
        $meetings = $user->can('viewAny', Meeting::class)
            ? $this->meetingQueries->base($user)->where('meetings.lead_id', $lead->id)
                ->with(array_values(array_filter(MeetingPresenter::ROW_WITH, fn (string $r) => ! str_starts_with($r, 'lead:'))))
                ->orderByDesc('start_at')
                ->limit(self::MEETING_LIMIT)
                ->get()
                ->each(fn (Meeting $m) => $m->setRelation('lead', $lead))
                ->map(fn (Meeting $m) => $this->meetingPresenter->row($m, $user))
            : collect();

        $viewBatches = $user->can('viewAny', Batch::class);
        $manageBatches = $viewBatches && ! $lead->trashed() && $user->hasPermission(Permissions::BATCH_MANAGE_LEADS);
        $batches = $viewBatches
            ? $lead->batches()->orderBy('batches.name')->get(['batches.id', 'batches.batch_number', 'batches.name', 'batches.status'])
                ->map(fn (Batch $b) => [
                    ...$b->only('id', 'batch_number', 'name'),
                    'status' => $b->status->value,
                    'archived' => $b->isArchived(),
                ])
            : null;

        return Inertia::render('Leads/Show', [
            'batches' => $batches,
            'lead' => [
                ...$this->presenter->row($lead, $user),
                ...$lead->only('first_name', 'last_name', 'alternate_phone', 'designation', 'country', 'pincode', 'lost_reason_notes'),
                'converted_at' => $lead->converted_at?->toIso8601String(),
                'lost_at' => $lead->lost_at?->toIso8601String(),
                'last_contacted_at' => $lead->last_contacted_at?->toIso8601String(),
                'lost_reason' => $lead->lostReason?->only('id', 'name'),
                'creator' => $lead->creator?->only('id', 'name'),
                'duplicate_of' => $showDuplicateOf ? $duplicateOf->only('id', 'lead_number', 'full_name') : null,
                ...(LeadValue::enabled() ? ['probability' => $lead->status?->probability] : []),
            ],
            'customFields' => $this->customFields->valuesFor($lead),
            'notes' => $notes,
            'activities' => [
                'data' => $activities->take(self::TIMELINE_PAGE_SIZE)->map(fn ($a) => $this->presenter->activity($a))->values(),
                'has_more' => $activities->count() > self::TIMELINE_PAGE_SIZE,
            ],
            'attachments' => $attachments,
            'enquiries' => $enquiries,
            'facebook' => $this->facebookSummary($lead, $user),
            'assignments' => $assignments,
            'followups' => $followups->values(),
            'followupForm' => $canCreateFollowup || $followups->isNotEmpty() ? [
                ...$this->followupOptions->form($user),
                'default_assignee' => $canCreateFollowup ? $this->followupService->defaultAssignee($lead, $user)->id : null,
            ] : null,
            'meetings' => $meetings->values(),
            'meetingForm' => $canCreateMeeting || $meetings->isNotEmpty() ? [
                ...$this->meetingOptions->form($user),
                'default_host' => $canCreateMeeting ? $this->meetingParticipants->defaultHost($lead, $user)->id : null,
            ] : null,
            'counts' => [
                'followups' => $followups->where('status', 'pending')->count(),
                'meetings' => $meetings->whereIn('status', MeetingStatus::openValues())->count(),
                'notes' => $notes->count(),
                'attachments' => count($attachments),
                'enquiries' => $lead->enquiries()->count(),
            ],
            'options' => [
                'statuses' => $this->options->statuses(),
                'lostReasons' => $this->options->lostReasons(),
                'priorities' => $this->options->priorities(),
                'assignees' => $user->can('assign', $lead) ? $this->options->assignableUsers($user) : [],
                'noteVisibilities' => array_map(fn ($v) => $v->value, $this->notes->allowedVisibilities($user)),
                'maxUploadKb' => LeadAttachmentService::MAX_KILOBYTES,
                'allowedExtensions' => LeadAttachmentService::ALLOWED_EXTENSIONS,
            ],
            'can' => [
                'update' => $user->can('update', $lead),
                'changeStatus' => $user->can('changeStatus', $lead),
                'assign' => $user->can('assign', $lead),
                'delete' => $user->can('delete', $lead),
                'restore' => $user->can('restore', $lead),
                'addNote' => $user->can('addNote', $lead),
                'viewAttachments' => $canViewAttachments,
                'uploadAttachment' => $user->can('uploadAttachment', $lead),
                'downloadAttachment' => $user->can('downloadAttachment', $lead),
                'createFollowup' => $canCreateFollowup,
                'createMeeting' => $canCreateMeeting,
                'manageBatches' => $manageBatches,
                'createBatch' => $manageBatches && $user->can('create', Batch::class),
                'manageBatchTrainers' => $manageBatches && $user->hasPermission(Permissions::BATCH_MANAGE_TRAINERS),
            ],
        ]);
    }

    public function edit(Request $request, Lead $lead): Response
    {
        $this->authorize('update', $lead);

        return Inertia::render('Leads/Form', $this->formProps($request->user(), $lead));
    }

    public function update(LeadRequest $request, Lead $lead): RedirectResponse
    {
        $this->leads->update($lead, $request->validated(), $request->user());

        return redirect()->route('leads.show', $lead)->with('success', "Lead {$lead->lead_number} updated.");
    }

    public function updatePriority(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorize('update', $lead);

        $data = $request->validate(['priority' => ['required', Rule::enum(LeadPriority::class)]]);
        $this->leads->changePriority($lead, LeadPriority::from($data['priority']), $request->user());

        return back()->with('success', 'Priority updated.');
    }

    public function destroy(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorize('delete', $lead);

        $this->leads->archive($lead, $request->user());

        return redirect()->route('leads.index')->with('success', "Lead {$lead->lead_number} archived.");
    }

    public function restore(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorize('restore', $lead);

        $this->leads->restore($lead, $request->user());

        return redirect()->route('leads.show', $lead)->with('success', "Lead {$lead->lead_number} restored.");
    }

    public function activities(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('view', $lead);

        $page = $this->notes->scopeTimeline($lead->activities(), $lead, $request->user())
            ->with('user:id,name')->latest('id')
            ->when($request->integer('before'), fn ($q, $before) => $q->where('id', '<', $before))
            ->limit(self::TIMELINE_PAGE_SIZE + 1)
            ->get();

        return response()->json([
            'data' => $page->take(self::TIMELINE_PAGE_SIZE)->map(fn ($a) => $this->presenter->activity($a))->values(),
            'has_more' => $page->count() > self::TIMELINE_PAGE_SIZE,
        ]);
    }

    /**
     * Lead 360 "Integration" section. Meta ids and local names only; the
     * event ledger status is shown only to facebook.manage users.
     */
    private function facebookSummary(Lead $lead, User $user): ?array
    {
        if (! $lead->facebook_lead_id && ! $lead->facebook_form_id) {
            return null;
        }

        $form = $lead->facebook_form_id
            ? FacebookForm::query()->with('page:id,page_id,page_name')->where('form_id', $lead->facebook_form_id)->latest('id')->first()
            : null;
        $pageName = $form?->page?->page_name
            ?? ($lead->facebook_page_id ? FacebookPage::query()->where('page_id', $lead->facebook_page_id)->value('page_name') : null);
        $first = $lead->enquiries()->where('channel', LeadEnquiry::CHANNEL_FACEBOOK)->oldest('received_at')->first(['metadata_json', 'received_at']);
        $canManage = $user->hasPermission(Permissions::FACEBOOK_MANAGE);
        $event = $canManage && $lead->facebook_lead_id
            ? FacebookWebhookEvent::query()->where('leadgen_id', $lead->facebook_lead_id)->first(['id', 'processing_status', 'origin', 'received_at'])
            : null;

        return [
            'platform' => ($first?->metadata_json['platform'] ?? null) === 'ig' ? 'Instagram' : 'Facebook',
            'leadgen_id' => $lead->facebook_lead_id,
            'page_id' => $lead->facebook_page_id,
            'page_name' => $pageName,
            'form_id' => $lead->facebook_form_id,
            'form_name' => $form?->form_name ?? ($first?->metadata_json['form_name'] ?? null),
            'campaign_id' => $lead->facebook_campaign_id,
            'campaign_name' => $first?->metadata_json['campaign_name'] ?? null,
            'adset_id' => $lead->facebook_adset_id,
            'adset_name' => $first?->metadata_json['adset_name'] ?? null,
            'ad_id' => $lead->facebook_ad_id,
            'ad_name' => $first?->metadata_json['ad_name'] ?? null,
            'submitted_at' => $first?->received_at?->toIso8601String(),
            'facebook_enquiries' => $lead->enquiries()->where('channel', LeadEnquiry::CHANNEL_FACEBOOK)->count(),
            'event' => $event ? [
                'status' => $event->processing_status?->label(),
                'origin' => $event->origin,
                'received_at' => $event->received_at?->toIso8601String(),
                'url' => route('admin.integrations.facebook.events.index', ['search' => $lead->facebook_lead_id]),
            ] : null,
        ];
    }

    private function formProps(User $user, ?Lead $lead): array
    {
        return [
            'lead' => $lead ? [
                ...$lead->only(
                    'id', 'lead_number', 'first_name', 'last_name', 'email', 'phone', 'alternate_phone',
                    'company_name', 'designation', 'city', 'state', 'country', 'pincode',
                    'source_id', 'campaign_id',
                ),
                ...(LeadValue::enabled() ? ['estimated_value' => $lead->estimated_value] : []),
                'priority' => $lead->priority?->value,
            ] : null,
            'customFields' => $this->customFields->valuesFor($lead),
            'options' => [
                'statuses' => array_values(array_filter($this->options->statuses(), fn ($s) => ! $s['is_won'] && ! $s['is_lost'])),
                'sources' => $this->options->sources(),
                'campaigns' => $this->options->campaigns(),
                'priorities' => $this->options->priorities(),
                'assignees' => ! $lead && $user->hasPermission(Permissions::LEAD_ASSIGN) ? $this->options->assignableUsers($user) : [],
                'defaultCountry' => 'India',
            ],
            'can' => [
                'assign' => ! $lead && $user->hasPermission(Permissions::LEAD_ASSIGN),
                'editSource' => ! $lead || $user->hasPermission(Permissions::LEAD_EDIT_SOURCE),
                'leadValue' => LeadValue::enabled(),
            ],
        ];
    }
}
