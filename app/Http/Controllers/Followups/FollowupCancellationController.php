<?php

namespace App\Http\Controllers\Followups;

use App\Http\Controllers\Controller;
use App\Models\Followup;
use App\Services\Followups\FollowupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FollowupCancellationController extends Controller
{
    public function __construct(private readonly FollowupService $followups) {}

    public function store(Request $request, Followup $followup): RedirectResponse
    {
        $this->authorize('cancel', $followup);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $this->followups->cancel($followup, $data['reason'] ?? null, $request->user());

        return back()->with('success', 'Follow-up cancelled.');
    }
}
