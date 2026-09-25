<?php

namespace App\Http\Controllers\Meetings;

use App\Enums\AttendanceStatus;
use App\Enums\MeetingParticipantType;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Services\Meetings\MeetingParticipantService;
use App\Services\Meetings\MeetingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Participant management on an existing meeting, the participant's own RSVP,
 * and the internal-user autocomplete (authorized, active users only).
 */
class MeetingParticipantController extends Controller
{
    public function __construct(
        private readonly MeetingService $meetings,
        private readonly MeetingParticipantService $participants,
    ) {}

    public function store(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->authorize('manageParticipants', $meeting);

        $data = $request->validate([
            'type' => ['required', Rule::enum(MeetingParticipantType::class)],
            'user_id' => ['required_if:type,user', 'nullable', 'integer'],
            'name' => ['required_if:type,external', 'nullable', 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:30'],
            'override_conflict' => ['boolean'],
        ]);

        $participant = $this->meetings->addParticipant($meeting, $data, $request->user(), $request->boolean('override_conflict'));

        return back()->with('success', "{$participant->name} added to the meeting.");
    }

    public function destroy(Request $request, Meeting $meeting, MeetingParticipant $participant): RedirectResponse
    {
        abort_unless((int) $participant->meeting_id === $meeting->id, 404);
        $this->authorize('manageParticipants', $meeting);

        $this->meetings->removeParticipant($meeting, $participant, $request->user());

        return back()->with('success', "{$participant->name} removed from the meeting.");
    }

    public function respond(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->authorize('respond', $meeting);

        $data = $request->validate(['attendance_status' => ['required', Rule::in([AttendanceStatus::Confirmed->value, AttendanceStatus::Declined->value])]]);

        $participant = $meeting->participants()
            ->where('participant_type', MeetingParticipantType::User->value)
            ->where('user_id', $request->user()->id)
            ->first();
        abort_unless($participant, 403);

        $this->meetings->respond($meeting, $participant, AttendanceStatus::from($data['attendance_status']), $request->user());

        return back()->with('success', $data['attendance_status'] === 'confirmed' ? 'Attendance confirmed.' : 'You declined this meeting.');
    }

    public function search(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('create', Meeting::class), 403);

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'lead_id' => ['nullable', 'integer'],
        ]);

        $lead = null;
        if (! empty($data['lead_id'])) {
            $lead = Lead::query()->visibleTo($user)->find((int) $data['lead_id']);
            abort_unless($lead, 404);
        }

        return response()->json(['data' => $this->participants->searchInvitable($user, $data['q'] ?? null, $lead)]);
    }
}
