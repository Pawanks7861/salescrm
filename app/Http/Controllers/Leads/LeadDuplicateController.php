<?php

namespace App\Http\Controllers\Leads;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Services\Leads\LeadDuplicateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Live duplicate warning for the create form. Only leads the user is allowed
 * to see are returned — hidden matches are never revealed, not even as a count.
 */
class LeadDuplicateController extends Controller
{
    public function __construct(private readonly LeadDuplicateService $duplicates) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('create', Lead::class);

        $data = $request->validate([
            'phone' => ['nullable', 'string', 'max:30'],
            'alternate_phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'string', 'max:191'],
            'exclude' => ['nullable', 'integer'],
        ]);

        $matches = $this->duplicates->findVisibleMatches($data, $request->user(), $data['exclude'] ?? null);

        return response()->json([
            'matches' => $matches->map(fn (array $m) => [
                'id' => $m['lead']->id,
                'lead_number' => $m['lead']->lead_number,
                'full_name' => $m['lead']->full_name,
                'status' => $m['lead']->status?->only('name', 'color'),
                'assignee' => $m['lead']->assignee?->name,
                'matched_on' => $m['matched_on'],
                'created_at' => $m['lead']->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }
}
