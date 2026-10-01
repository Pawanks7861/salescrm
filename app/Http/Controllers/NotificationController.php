<?php

namespace App\Http\Controllers;

use App\Models\Followup;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * In-app notification center. Users only ever touch their own notifications.
 * Stored payloads are display hints, not authorization: before showing a
 * message or following a link, access to the referenced follow-up / lead is
 * re-checked in one batched query, and notifications the user can no longer
 * access are masked as stale.
 */
class NotificationController extends Controller
{
    private const RECENT_LIMIT = 8;

    public const STALE_MESSAGE = 'This item is no longer available to you.';

    public function index(Request $request): Response
    {
        $user = $request->user();
        $page = $user->notifications()->paginate(20)->withQueryString();

        $rows = $this->present($page->getCollection(), $user);

        return Inertia::render('Notifications/Index', [
            'notifications' => [
                'data' => $rows,
                'links' => $page->linkCollection(),
                'meta' => ['total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem(), 'last_page' => $page->lastPage(), 'current_page' => $page->currentPage()],
            ],
            'unread' => $user->unreadNotifications()->count(),
        ]);
    }

    public function recent(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'unread' => $user->unreadNotifications()->count(),
            'data' => $this->present($user->notifications()->limit(self::RECENT_LIMIT)->get(), $user),
        ]);
    }

    public function read(Request $request, string $notification): RedirectResponse|JsonResponse
    {
        $this->find($request->user(), $notification)->markAsRead();

        return $request->wantsJson() ? response()->json(['ok' => true]) : back();
    }

    public function readAll(Request $request): RedirectResponse|JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return $request->wantsJson() ? response()->json(['ok' => true]) : back()->with('success', 'All notifications marked as read.');
    }

    /** Marks read and navigates to the target; the target route re-authorizes. */
    public function open(Request $request, string $notification): RedirectResponse
    {
        $user = $request->user();
        $model = $this->find($user, $notification);
        $model->markAsRead();

        $row = $this->present(collect([$model]), $user)[0];

        if ($row['stale'] || ! $row['target']) {
            return redirect()->route('notifications.index')->with('error', self::STALE_MESSAGE);
        }

        return redirect()->to($row['target']);
    }

    private function find(User $user, string $id): DatabaseNotification
    {
        return $user->notifications()->whereKey($id)->firstOrFail();
    }

    /**
     * @param  Collection<int, DatabaseNotification>  $notifications
     * @return array<int, array<string, mixed>>
     */
    private function present(Collection $notifications, User $user): array
    {
        $followupIds = $notifications->map(fn ($n) => $n->data['followup_id'] ?? null)->filter()->unique()->values();
        $meetingIds = $notifications->map(fn ($n) => $n->data['meeting_id'] ?? null)->filter()->unique()->values();
        $leadIds = $notifications->filter(fn ($n) => empty($n->data['followup_id']) && empty($n->data['meeting_id']) && empty($n->data['call_id']))->map(fn ($n) => $n->data['lead_id'] ?? null)->filter()->unique()->values();

        $visibleFollowups = $followupIds->isEmpty() ? collect() : Followup::query()->visibleTo($user)->whereIn('followups.id', $followupIds)->pluck('followups.id')->flip();
        $visibleMeetings = $meetingIds->isEmpty() ? collect() : Meeting::query()->visibleTo($user)->whereIn('meetings.id', $meetingIds)->pluck('meetings.id')->flip();
        $visibleLeads = $leadIds->isEmpty() ? collect() : Lead::query()->visibleTo($user)->whereIn('leads.id', $leadIds)->pluck('leads.id')->flip();

        return $notifications->map(function (DatabaseNotification $n) use ($visibleFollowups, $visibleMeetings, $visibleLeads) {
            $data = $n->data;
            $followupId = $data['followup_id'] ?? null;
            $meetingId = $data['meeting_id'] ?? null;
            $leadId = $data['lead_id'] ?? null;

            [$stale, $target] = match (true) {
                (bool) $followupId => [! $visibleFollowups->has($followupId), route('followups.show', $followupId, false)],
                (bool) $meetingId => [! $visibleMeetings->has($meetingId), route('meetings.show', $meetingId, false)],
                // Legacy call notifications: the calling module was removed, so there is nothing to open.
                ! empty($data['call_id']) => [true, null],
                (bool) $leadId => [! $visibleLeads->has($leadId), route('leads.show', $leadId, false)],
                default => [false, null],
            };

            return [
                'id' => $n->id,
                'event' => $data['event'] ?? null,
                'category' => $data['category'] ?? null,
                'message' => $stale ? self::STALE_MESSAGE : ($data['message'] ?? ''),
                'stale' => $stale,
                'target' => $stale ? null : $target,
                'read' => $n->read_at !== null,
                'created_at' => $n->created_at?->toIso8601String(),
            ];
        })->values()->all();
    }
}
