<?php

namespace App\Http\Controllers\Meetings;

use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Services\Meetings\MeetingNoteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MeetingNoteController extends Controller
{
    public function __construct(private readonly MeetingNoteService $notes) {}

    public function store(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->authorize('addNote', $meeting);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $this->notes->add($meeting, $request->user(), trim($validated['body']));

        return back()->with('success', 'Note saved. The salesperson has been notified.');
    }
}
