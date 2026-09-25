<?php

namespace App\Http\Controllers\Followups;

use App\Http\Controllers\Controller;
use App\Http\Requests\Followups\CompleteFollowupRequest;
use App\Models\Followup;
use App\Services\Followups\FollowupService;
use Illuminate\Http\RedirectResponse;

class FollowupCompletionController extends Controller
{
    public function __construct(private readonly FollowupService $followups) {}

    public function store(CompleteFollowupRequest $request, Followup $followup): RedirectResponse
    {
        $this->authorize('complete', $followup);

        $result = $this->followups->complete($followup, $request->validated(), $request->user());

        return back()->with('success', $result['next'] ? 'Follow-up completed and next one scheduled.' : 'Follow-up completed.');
    }
}
