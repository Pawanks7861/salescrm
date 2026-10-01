<?php

namespace App\Http\Controllers\Batches;

use App\Enums\BatchStatus;
use App\Http\Controllers\Controller;
use App\Http\Presenters\LeadPresenter;
use App\Http\Presenters\TrainerPresenter;
use App\Http\Requests\Batches\StoreBatchRequest;
use App\Http\Requests\Batches\UpdateBatchRequest;
use App\Models\Batch;
use App\Models\Lead;
use App\Models\User;
use App\Services\Batches\BatchService;
use App\Services\Leads\LeadOptions;
use App\Services\Leads\LeadQueryService;
use App\Services\Leads\LeadVisibility;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Batch pages. Every lead count and lead row is computed through
 * LeadVisibility, so a batch never reveals a lead the viewer cannot already see.
 */
class BatchController extends Controller
{
    private const LOOKUP_LIMIT = 20;

    private const TRAINER_FILTER_LIMIT = 200;

    public function __construct(
        private readonly BatchService $batches,
        private readonly LeadQueryService $leadQueries,
        private readonly LeadPresenter $leadPresenter,
        private readonly LeadOptions $leadOptions,
        private readonly LeadVisibility $visibility,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Batch::class);
        $user = $request->user();

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(BatchStatus::class)],
            'start_from' => ['nullable', 'date'],
            'start_to' => ['nullable', 'date'],
            'end_from' => ['nullable', 'date'],
            'end_to' => ['nullable', 'date'],
            'trainer' => ['nullable', 'regex:/^(me|\d+)$/'],
        ]);

        $trainerId = match ($filters['trainer'] ?? null) {
            null => null,
            'me' => $user->id,
            default => (int) $filters['trainer'],
        };

        $query = Batch::query()
            ->with(['creator:id,name', 'trainers' => $this->trainerColumns(...)])
            ->withCount(['leads as leads_count' => fn (Builder $q) => $q->visibleTo($user)])
            ->when($filters['search'] ?? null, fn (Builder $q, string $term) => $this->search($q, $term))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($trainerId, fn (Builder $q, int $id) => $q->whereExists(fn ($s) => $s->from('batch_trainers')
                ->whereColumn('batch_trainers.batch_id', 'batches.id')
                ->where('batch_trainers.trainer_id', $id)));
        $this->dateRange($query, 'start_date', $filters['start_from'] ?? null, $filters['start_to'] ?? null);
        $this->dateRange($query, 'end_date', $filters['end_from'] ?? null, $filters['end_to'] ?? null);

        $batches = $query
            ->orderByRaw('CASE WHEN status = ? THEN 1 ELSE 0 END', [BatchStatus::Archived->value])
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Batch $batch) => [
                ...$this->summary($batch),
                'description' => Str::limit((string) $batch->description, 140) ?: null,
                'leads_count' => $batch->leads_count,
                'trainers' => $this->trainers($batch),
                'can' => [
                    'update' => $user->can('update', $batch),
                    'archive' => $user->can('archive', $batch),
                    'restore' => $user->can('restore', $batch),
                    'delete' => $user->can('delete', $batch),
                ],
            ]);

        return Inertia::render('Batches/Index', [
            'batches' => $batches,
            'filters' => $filters,
            'statuses' => BatchStatus::options(),
            'trainerOptions' => $this->trainerFilterOptions(),
            'isTrainer' => $user->isTrainer(),
            'can' => ['create' => $user->can('create', Batch::class)],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Batch::class);

        return Inertia::render('Batches/Form', $this->formProps($request->user(), null));
    }

    public function store(StoreBatchRequest $request): RedirectResponse
    {
        $user = $request->user();
        $leadIds = array_map('intval', $request->validated('lead_ids') ?? []);
        $trainerIds = array_map('intval', $request->validated('trainer_ids') ?? []);
        abort_if($leadIds !== [] && ! $this->managesLeads($user), 403);
        abort_if($trainerIds !== [] && ! $user->hasPermission(Permissions::BATCH_MANAGE_TRAINERS), 403);

        [$batch, $result] = $this->batches->create($request->safe()->only(['name', 'description', 'status', 'start_date', 'end_date']), $user, $leadIds, $trainerIds);

        $message = "Batch {$batch->batch_number} created.";
        if ($trainerIds !== []) {
            $message .= ' '.(count($trainerIds) === 1 ? '1 trainer' : count($trainerIds).' trainers').' assigned.';
        }
        if ($result->selected > 0) {
            $message .= ' '.$result->addedMessage($batch->name);
        }

        return redirect()->route('batches.show', $batch)->with('success', $message);
    }

    public function show(Request $request, Batch $batch): Response
    {
        $this->authorize('view', $batch);
        $user = $request->user();

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'integer'],
            'source' => ['nullable', 'integer'],
            'assignee' => ['nullable', 'string', 'max:20'],
            'created_from' => ['nullable', 'date'],
            'created_to' => ['nullable', 'date'],
            'followup' => ['nullable', 'in:overdue,today,upcoming,none'],
            'sort' => ['nullable', Rule::in(LeadQueryService::SORTS)],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'in:25,50,100'],
        ]);

        $leads = $this->leadQueries->filtered($user, [...$filters, 'batch' => $batch->id])
            ->select('leads.*')
            ->addSelect(['batch_added_at' => DB::table('batch_leads')->select('created_at')
                ->whereColumn('batch_leads.lead_id', 'leads.id')
                ->where('batch_leads.batch_id', $batch->id)
                ->limit(1)])
            ->with(LeadPresenter::ROW_WITH)
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString()
            ->through(fn (Lead $lead) => [
                ...$this->leadPresenter->row($lead, $user),
                'added_at' => $lead->batch_added_at ? Carbon::parse($lead->batch_added_at, 'UTC')->toIso8601String() : null,
            ]);

        $batch->load(['creator:id,name', 'trainers' => $this->trainerColumns(...)]);

        return Inertia::render('Batches/Show', [
            'batch' => [
                ...$this->summary($batch),
                'description' => $batch->description,
                'updated_at' => $batch->updated_at?->toIso8601String(),
                'trainers' => $this->trainers($batch),
            ],
            'summary' => $this->statusSummary($batch, $user),
            'leads' => $leads,
            'filters' => $filters,
            'options' => [
                'statuses' => $this->leadOptions->statuses(false),
                'activeStatuses' => $this->leadOptions->statuses(),
                'sources' => $this->leadOptions->sources(),
                'users' => $this->leadOptions->filterableUsers($user),
            ],
            'can' => [
                'update' => $user->can('update', $batch),
                'archive' => $user->can('archive', $batch),
                'restore' => $user->can('restore', $batch),
                'delete' => $user->can('delete', $batch),
                'addLeads' => $user->can('addLeads', $batch),
                'removeLeads' => $user->can('removeLeads', $batch),
                'addTrainers' => $user->can('addTrainers', $batch),
                'removeTrainers' => $user->can('removeTrainers', $batch),
                'filterByUser' => $this->visibility->tier($user) !== LeadVisibility::OWN,
            ],
        ]);
    }

    public function edit(Request $request, Batch $batch): Response
    {
        $this->authorize('update', $batch);

        return Inertia::render('Batches/Form', $this->formProps($request->user(), $batch));
    }

    public function update(UpdateBatchRequest $request, Batch $batch): RedirectResponse
    {
        $user = $request->user();

        // Trainers first: an invalid trainer list rejects the request before any detail is saved.
        if ($request->hasTrainerIds()) {
            abort_unless($user->hasPermission(Permissions::BATCH_MANAGE_TRAINERS), 403);
            $this->batches->syncTrainers($batch, $request->trainerIds(), $user);
        }

        $this->batches->update($batch, $request->safe()->except('trainer_ids'), $user);

        return redirect()->route('batches.show', $batch)->with('success', "Batch {$batch->batch_number} updated.");
    }

    public function archive(Request $request, Batch $batch): RedirectResponse
    {
        $this->authorize('archive', $batch);
        $this->batches->archive($batch, $request->user());

        return back()->with('success', "Batch {$batch->batch_number} archived. It stays viewable but no longer accepts new leads.");
    }

    public function restore(Request $request, Batch $batch): RedirectResponse
    {
        $this->authorize('restore', $batch);
        $this->batches->restore($batch, $request->user());

        return back()->with('success', "Batch {$batch->batch_number} restored.");
    }

    public function destroy(Request $request, Batch $batch): RedirectResponse
    {
        $this->authorize('delete', $batch);
        $this->batches->delete($batch, $request->user());

        return redirect()->route('batches.index')->with('success', "Batch {$batch->batch_number} deleted. Its leads were not changed.");
    }

    /** Batches that can receive leads, for the "Add to batch" pickers. */
    public function lookup(Request $request): JsonResponse
    {
        $user = $request->user();
        $term = trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? ''));

        $batches = Batch::query()->notArchived()
            ->withCount(['leads as leads_count' => fn (Builder $q) => $q->visibleTo($user)])
            ->when($term !== '', fn (Builder $q) => $this->search($q, $term))
            ->orderBy('name')
            ->limit(self::LOOKUP_LIMIT)
            ->get(['id', 'batch_number', 'name', 'status']);

        return response()->json([
            'results' => $batches->map(fn (Batch $b) => [
                ...$b->only('id', 'batch_number', 'name'),
                'status' => $b->status->value,
                'leads_count' => $b->leads_count,
            ]),
        ]);
    }

    private function search(Builder $query, string $term): Builder
    {
        $escaped = addcslashes(trim($term), '%_\\');

        return $query->where(fn (Builder $q) => $q
            ->where('name', 'like', '%'.$escaped.'%')
            ->orWhere('batch_number', 'like', strtoupper($escaped).'%'));
    }

    /**
     * Inclusive calendar-date range. The upper bound is "< next day" so it
     * also matches drivers that keep a time part, and the column index is used.
     */
    private function dateRange(Builder $query, string $column, ?string $from, ?string $to): void
    {
        if ($from) {
            $query->where($column, '>=', Carbon::parse($from)->toDateString());
        }
        if ($to) {
            $query->where($column, '<', Carbon::parse($to)->addDay()->toDateString());
        }
    }

    private function summary(Batch $batch): array
    {
        return [
            'id' => $batch->id,
            'batch_number' => $batch->batch_number,
            'name' => $batch->name,
            'status' => ['value' => $batch->status->value, 'label' => $batch->status->label(), 'color' => $batch->status->color()],
            ...$this->dates($batch),
            'creator' => $batch->creator?->only('id', 'name'),
            'created_at' => $batch->created_at?->toIso8601String(),
        ];
    }

    /** Visible leads in the batch per lead status (statuses are configurable, never hard-coded). */
    private function statusSummary(Batch $batch, User $user): array
    {
        $counts = $this->leadQueries->inBatch(Lead::query()->visibleTo($user), $batch->id)
            ->selectRaw('status_id, COUNT(*) as aggregate')
            ->groupBy('status_id')
            ->pluck('aggregate', 'status_id');

        $activeIds = array_column($this->leadOptions->statuses(), 'id');
        $statuses = collect($this->leadOptions->statuses(false))
            ->filter(fn (array $s) => in_array($s['id'], $activeIds, true) || ($counts[$s['id']] ?? 0) > 0)
            ->map(fn (array $s) => [...array_intersect_key($s, array_flip(['id', 'name', 'color', 'is_won', 'is_lost'])), 'count' => (int) ($counts[$s['id']] ?? 0)])
            ->values();

        return ['total' => (int) $counts->sum(), 'statuses' => $statuses];
    }

    private function formProps(User $user, ?Batch $batch): array
    {
        $batch?->load(['trainers' => $this->trainerColumns(...)]);

        return [
            'batch' => $batch ? [
                ...$batch->only('id', 'batch_number', 'name', 'description'),
                ...$this->dates($batch),
                'status' => $batch->status->value,
                'archived' => $batch->isArchived(),
                'trainers' => $this->trainers($batch),
            ] : null,
            'statuses' => BatchStatus::options(includeArchived: false),
            'leadOptions' => [
                'statuses' => $this->leadOptions->statuses(),
                'sources' => $this->leadOptions->sources(),
            ],
            'can' => [
                'manageLeads' => ! $batch && $this->managesLeads($user),
                'manageTrainers' => $user->hasPermission(Permissions::BATCH_MANAGE_TRAINERS),
            ],
        ];
    }

    private function trainerColumns(BelongsToMany $query): void
    {
        $query->select(TrainerPresenter::COLUMNS)->with('role:id,name')->orderBy('users.name');
    }

    private function trainers(Batch $batch): array
    {
        return $batch->trainers->map(fn (User $trainer) => TrainerPresenter::present($trainer))->values()->all();
    }

    /** Everyone currently assigned to a batch (bounded by assignments, never the whole users table). */
    private function trainerFilterOptions(): array
    {
        return User::withTrashed()
            ->whereIn('id', DB::table('batch_trainers')->select('trainer_id'))
            ->orderBy('name')
            ->limit(self::TRAINER_FILTER_LIMIT)
            ->get(['id', 'name'])
            ->map(fn (User $u) => $u->only('id', 'name'))
            ->all();
    }

    /** Plain "YYYY-MM-DD" strings (or null): calendar dates are never sent as timezone-shifted instants. */
    private function dates(Batch $batch): array
    {
        return [
            'start_date' => $batch->start_date?->toDateString(),
            'end_date' => $batch->end_date?->toDateString(),
        ];
    }

    private function managesLeads(User $user): bool
    {
        return $user->hasPermission(Permissions::BATCH_MANAGE_LEADS)
            && $user->hasAnyPermission(Permissions::LEAD_VIEW, Permissions::LEAD_VIEW_ALL);
    }
}
