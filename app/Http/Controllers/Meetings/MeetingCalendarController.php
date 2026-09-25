<?php

namespace App\Http\Controllers\Meetings;

use App\Http\Controllers\Controller;
use App\Http\Presenters\MeetingPresenter;
use App\Models\Meeting;
use App\Services\Meetings\MeetingOptions;
use App\Services\Meetings\MeetingQueryService;
use App\Services\Meetings\MeetingVisibility;
use App\Support\CrmTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Calendar page and the range-loaded events feed. The feed only returns
 * visible meetings overlapping the requested window (max MAX_RANGE_DAYS).
 */
class MeetingCalendarController extends Controller
{
    public const MAX_RANGE_DAYS = 62;

    public const MAX_EVENTS = 1000;

    public function __construct(
        private readonly MeetingQueryService $queries,
        private readonly MeetingPresenter $presenter,
        private readonly MeetingVisibility $visibility,
        private readonly MeetingOptions $options,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Meeting::class);
        $user = $request->user();

        return Inertia::render('Calendar/Index', [
            'options' => [
                ...$this->options->form($user),
                'users' => $this->options->filterableUsers($user),
            ],
            'can' => [
                'create' => $user->can('create', Meeting::class),
                'createWithoutLead' => $user->can('createWithoutLead', Meeting::class),
                'filterByUser' => $this->visibility->tier($user) !== MeetingVisibility::OWN,
            ],
        ]);
    }

    public function events(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Meeting::class);
        $user = $request->user();

        $data = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date'],
            'scope' => ['nullable', 'in:mine,all'],
            'host' => ['nullable', 'integer'],
            'type' => ['nullable', 'integer'],
            'hide_cancelled' => ['nullable', 'boolean'],
        ]);

        // Values without an offset are CRM-timezone wall-clock times (calendar coercion mode).
        $start = CarbonImmutable::parse($data['start'], CrmTime::tz())->utc();
        $end = CarbonImmutable::parse($data['end'], CrmTime::tz())->utc();

        if ($end->lte($start)) {
            throw ValidationException::withMessages(['end' => 'The end of the range must be after the start.']);
        }
        if ($start->diffInDays($end) > self::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages(['end' => 'The calendar range is too large.']);
        }

        $meetings = $this->queries->range($user, $start, $end, $data)
            ->with(['type:id,name,color', 'host:id,name', 'lead:id,lead_number,full_name,assigned_to,deleted_at'])
            ->limit(self::MAX_EVENTS)
            ->get();

        return response()->json([
            'data' => $meetings->map(fn (Meeting $m) => $this->presenter->event($m, $user))->values(),
        ]);
    }
}
