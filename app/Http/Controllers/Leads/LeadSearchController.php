<?php

namespace App\Http\Controllers\Leads;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Services\Leads\LeadQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Topbar quick search; always visibility-scoped and never audited per keystroke. */
class LeadSearchController extends Controller
{
    private const LIMIT = 8;

    public function __construct(private readonly LeadQueryService $queries) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);

        $term = trim((string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q']);

        $query = $this->queries->base($request->user())->with('status:id,name,color');
        $this->queries->search($query, $term);

        $results = $query->latest('id')->limit(self::LIMIT)->get(['id', 'lead_number', 'full_name', 'company_name', 'phone', 'status_id']);

        return response()->json([
            'results' => $results->map(fn (Lead $lead) => [
                'id' => $lead->id,
                'lead_number' => $lead->lead_number,
                'full_name' => $lead->full_name,
                'company_name' => $lead->company_name,
                'phone' => $lead->phone,
                'status' => $lead->status?->only('name', 'color'),
            ]),
        ]);
    }
}
