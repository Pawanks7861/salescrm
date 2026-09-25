<?php

namespace App\Http\Controllers\Leads;

use App\Enums\LeadPriority;
use App\Http\Controllers\Controller;
use App\Http\Presenters\LeadPresenter;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\User;
use App\Services\Leads\LeadOptions;
use App\Services\Leads\LeadQueryService;
use App\Services\Leads\LeadVisibility;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kanban board: one bounded query per active status (CARDS_PER_COLUMN cards)
 * plus a single grouped count; further cards are fetched per column on demand.
 */
class LeadPipelineController extends Controller
{
    public const CARDS_PER_COLUMN = 25;

    public function __construct(
        private readonly LeadQueryService $queries,
        private readonly LeadOptions $options,
        private readonly LeadPresenter $presenter,
        private readonly LeadVisibility $visibility,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Lead::class);
        $user = $request->user();
        $filters = $this->filters($request);

        $statuses = LeadStatus::query()->active()->ordered()->get();

        $counts = $this->queries->filtered($user, $filters)
            ->reorder()
            ->selectRaw('status_id, COUNT(*) as aggregate')
            ->groupBy('status_id')
            ->pluck('aggregate', 'status_id');

        $columns = $statuses->map(fn (LeadStatus $status) => [
            'status' => $status->only('id', 'name', 'color', 'is_won', 'is_lost', 'probability'),
            'count' => (int) ($counts[$status->id] ?? 0),
            'cards' => $this->cards($user, $filters, $status->id, 0),
        ]);

        return Inertia::render('Leads/Pipeline', [
            'columns' => $columns,
            'filters' => $filters,
            'perColumn' => self::CARDS_PER_COLUMN,
            'options' => [
                'sources' => $this->options->sources(),
                'campaigns' => $this->options->campaigns(),
                'users' => $this->options->filterableUsers($user),
                'priorities' => $this->options->priorities(),
                'lostReasons' => $this->options->lostReasons(),
            ],
            'can' => [
                'changeStatus' => $user->hasPermission(Permissions::LEAD_CHANGE_STATUS),
                'filterByUser' => $this->visibility->tier($user) !== LeadVisibility::OWN,
            ],
        ]);
    }

    public function more(Request $request, LeadStatus $status): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);

        $offset = max(0, $request->integer('offset'));

        return response()->json([
            'cards' => $this->cards($request->user(), $this->filters($request), $status->id, $offset),
        ]);
    }

    private function cards(User $user, array $filters, int $statusId, int $offset): array
    {
        return $this->queries->filtered($user, $filters)
            ->where('status_id', $statusId)
            ->with(['source:id,name', 'assignee:id,name'])
            ->offset($offset)
            ->limit(self::CARDS_PER_COLUMN)
            ->get()
            ->map(fn (Lead $lead) => $this->presenter->card($lead))
            ->all();
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'source' => ['nullable', 'integer'],
            'campaign' => ['nullable', 'integer'],
            'assignee' => ['nullable', 'string', 'max:20'],
            'priority' => ['nullable', Rule::enum(LeadPriority::class)],
        ]);
    }
}
