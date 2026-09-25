<?php

namespace App\Http\Controllers\Meetings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Meetings\CompleteMeetingRequest;
use App\Models\Meeting;
use App\Services\Meetings\MeetingCompletionService;
use Illuminate\Http\RedirectResponse;

class MeetingCompletionController extends Controller
{
    public function store(CompleteMeetingRequest $request, Meeting $meeting, MeetingCompletionService $completion): RedirectResponse
    {
        $this->authorize('complete', $meeting);

        $result = $completion->complete($meeting, $request->validated(), $request->user());

        return back()->with('success', $result['followup'] ? 'Meeting completed and follow-up scheduled.' : 'Meeting completed.');
    }
}
