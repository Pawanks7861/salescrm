<?php

namespace App\Http\Controllers\Followups;

use App\Http\Controllers\Controller;
use App\Models\Followup;
use App\Services\Followups\FollowupService;
use App\Support\CrmTime;
use App\Support\FollowupReminderOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FollowupRescheduleController extends Controller
{
    public function __construct(private readonly FollowupService $followups) {}

    public function store(Request $request, Followup $followup): RedirectResponse
    {
        $this->authorize('reschedule', $followup);

        $data = $request->validate([
            'scheduled_date' => ['required', 'date_format:Y-m-d'],
            'scheduled_time' => ['required', 'date_format:H:i'],
            'reason' => ['nullable', 'string', 'max:500'],
            'reminder_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.FollowupReminderOptions::MAX_MINUTES],
        ]);

        $new = $this->followups->reschedule($followup, $data, $request->user());

        return back()->with('success', 'Follow-up rescheduled to '.CrmTime::format($new->scheduled_at).'.');
    }
}
