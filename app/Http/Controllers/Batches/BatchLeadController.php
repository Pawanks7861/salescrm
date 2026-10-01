<?php

namespace App\Http\Controllers\Batches;

use App\Http\Controllers\Controller;
use App\Http\Requests\Batches\AddBatchLeadsRequest;
use App\Http\Requests\Batches\RemoveBatchLeadsRequest;
use App\Models\Batch;
use App\Models\Lead;
use App\Services\Batches\BatchService;
use App\Services\Leads\LeadQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Batch membership. Used by the batch page, the lead list bulk action and
 * Lead 360, so all membership rules live in BatchService only.
 */
class BatchLeadController extends Controller
{
    private const SEARCH_PAGE_SIZE = 20;

    public function __construct(
        private readonly BatchService $batches,
        private readonly LeadQueryService $leadQueries,
    ) {}

    public function store(AddBatchLeadsRequest $request, Batch $batch): RedirectResponse
    {
        $result = $this->batches->addLeads($batch, $request->leadIds(), $request->user());

        return back()->with('success', $result->addedMessage($batch->name));
    }

    public function destroy(Request $request, Batch $batch, Lead $lead): RedirectResponse
    {
        $this->authorize('removeLeads', $batch);
        $this->authorize('view', $lead);

        $result = $this->batches->removeLeads($batch, [$lead->id], $request->user());

        return back()->with('success', $result->changed
            ? "{$lead->lead_number} removed from {$batch->name}. The lead itself was not changed."
            : "{$lead->lead_number} is not in {$batch->name}.");
    }

    public function bulkDestroy(RemoveBatchLeadsRequest $request, Batch $batch): RedirectResponse
    {
        $result = $this->batches->removeLeads($batch, $request->leadIds(), $request->user());

        return back()->with('success', $result->removedMessage($batch->name));
    }

    /**
     * Paginated, visibility-scoped lead search for the batch lead selector.
     * Returns only the fields the selector shows; never the whole lead table.
     */
    public function search(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);
        $user = $request->user();

        $params = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'integer'],
            'source' => ['nullable', 'integer'],
            'batch' => ['nullable', 'integer'],
        ]);

        $query = $this->leadQueries->base($user)
            ->with(['status:id,name,color', 'source:id,name', 'assignee:id,name'])
            ->when($params['status'] ?? null, fn ($q, $v) => $q->where('status_id', (int) $v))
            ->when($params['source'] ?? null, fn ($q, $v) => $q->where('source_id', (int) $v));
        $this->leadQueries->search($query, (string) ($params['q'] ?? ''));

        $page = $query->latest('id')->paginate(self::SEARCH_PAGE_SIZE, [
            'id', 'lead_number', 'full_name', 'company_name', 'phone', 'email', 'status_id', 'source_id', 'assigned_to',
        ]);

        $batch = isset($params['batch']) ? Batch::query()->find($params['batch']) : null;
        $members = $batch && $user->can('view', $batch)
            ? DB::table('batch_leads')->where('batch_id', $batch->id)->whereIn('lead_id', $page->getCollection()->pluck('id'))->pluck('lead_id')->flip()
            : collect();

        return response()->json([
            'data' => $page->getCollection()->map(fn (Lead $lead) => [
                ...$lead->only('id', 'lead_number', 'full_name', 'company_name', 'phone', 'email'),
                'status' => $lead->status?->only('id', 'name', 'color'),
                'source' => $lead->source?->name,
                'owner' => $lead->assignee?->name,
                'in_batch' => $members->has($lead->id),
            ])->values(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
        ]);
    }
}
