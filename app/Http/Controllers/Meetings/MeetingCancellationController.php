<?php

namespace App\Http\Controllers\Meetings;

use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Services\Meetings\MeetingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MeetingCancellationController extends Controller
{
    public function store(Request $request, Meeting $meeting, MeetingService $meetings): RedirectResponse
    {
        $this->authorize('cancel', $meeting);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        $meetings->cancel($meeting, $data['reason'] ?? null, $request->user());

        return back()->with('success', 'Meeting cancelled.');
    }
}
