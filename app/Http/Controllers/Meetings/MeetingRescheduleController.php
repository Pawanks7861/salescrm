<?php

namespace App\Http\Controllers\Meetings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Meetings\RescheduleMeetingRequest;
use App\Models\Meeting;
use App\Services\Meetings\MeetingService;
use Illuminate\Http\RedirectResponse;

/** Used by the reschedule modal and by calendar drag & drop (same full workflow). */
class MeetingRescheduleController extends Controller
{
    public function store(RescheduleMeetingRequest $request, Meeting $meeting, MeetingService $meetings): RedirectResponse
    {
        $this->authorize('reschedule', $meeting);

        $new = $meetings->reschedule($meeting, collect($request->validated())->except('override_conflict')->all(), $request->user(), $request->boolean('override_conflict'));

        return $request->boolean('stay')
            ? back()->with('success', "Meeting rescheduled as {$new->meeting_number}.")
            : redirect()->route('meetings.show', $new)->with('success', "Meeting rescheduled as {$new->meeting_number}.");
    }
}
