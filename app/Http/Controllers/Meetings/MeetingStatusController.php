<?php

namespace App\Http\Controllers\Meetings;

use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Services\Meetings\MeetingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Confirm, start and no-show transitions. */
class MeetingStatusController extends Controller
{
    public function __construct(private readonly MeetingService $meetings) {}

    public function confirm(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->authorize('confirm', $meeting);

        $this->meetings->confirm($meeting, $request->user());

        return back()->with('success', 'Meeting confirmed.');
    }

    public function start(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->authorize('start', $meeting);

        $this->meetings->start($meeting, $request->user());

        return back()->with('success', 'Meeting started.');
    }

    public function noShow(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->authorize('noShow', $meeting);

        $data = $request->validate([
            'absent_participant_ids' => ['nullable', 'array', 'max:50'],
            'absent_participant_ids.*' => ['integer'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $this->meetings->markNoShow($meeting, $data, $request->user());

        return back()->with('success', 'Meeting marked as no-show.');
    }
}
