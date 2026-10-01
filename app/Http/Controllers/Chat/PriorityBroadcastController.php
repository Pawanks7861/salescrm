<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\StorePriorityBroadcastRequest;
use App\Models\PriorityBroadcast;
use App\Models\PriorityBroadcastRecipient;
use App\Services\Chat\PriorityBroadcastService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PriorityBroadcastController extends Controller
{
    public function __construct(private readonly PriorityBroadcastService $broadcasts) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PriorityBroadcast::class);

        $status = $request->string('status')->toString();

        $broadcasts = PriorityBroadcast::query()
            ->with('sender:id,name')
            ->withCount([
                'recipients as read_count' => fn ($q) => $q->whereNotNull('read_at'),
                'recipients as acknowledged_count' => fn ($q) => $q->whereNotNull('acknowledged_at'),
            ])
            ->when($status === 'active', fn ($q) => $q->active())
            ->when($status === 'expired', fn ($q) => $q->whereNotNull('expires_at')->where('expires_at', '<=', now()))
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (PriorityBroadcast $b) => $this->summary($b));

        return Inertia::render('PriorityBroadcasts/Index', [
            'broadcasts' => $broadcasts,
            'filters' => ['status' => in_array($status, ['active', 'expired'], true) ? $status : ''],
            'canSend' => $request->user()->can('create', PriorityBroadcast::class),
        ]);
    }

    public function show(Request $request, PriorityBroadcast $broadcast): Response
    {
        $this->authorize('view', $broadcast);

        $user = $request->user();
        $recipient = PriorityBroadcastRecipient::where('broadcast_id', $broadcast->id)->where('user_id', $user->id)->first();

        if ($recipient) {
            $this->broadcasts->markRead($recipient);
        }

        $showStats = $user->can('viewStats', $broadcast);
        $broadcast->load('sender:id,name');

        return Inertia::render('PriorityBroadcasts/Show', [
            'broadcast' => [
                ...$this->summary($broadcast->loadCount([
                    'recipients as read_count' => fn ($q) => $q->whereNotNull('read_at'),
                    'recipients as acknowledged_count' => fn ($q) => $q->whereNotNull('acknowledged_at'),
                ])),
                'message' => $broadcast->message,
            ],
            'recipient' => $recipient ? [
                'read_at' => $recipient->read_at?->toIso8601String(),
                'acknowledged_at' => $recipient->acknowledged_at?->toIso8601String(),
            ] : null,
            'recipients' => $showStats
                ? PriorityBroadcastRecipient::query()
                    ->where('broadcast_id', $broadcast->id)
                    ->with('user:id,name,designation,deleted_at')
                    ->orderByRaw('acknowledged_at IS NULL DESC')
                    ->orderBy('user_id')
                    ->paginate(50)
                    ->withQueryString()
                    ->through(fn (PriorityBroadcastRecipient $r) => [
                        'id' => $r->id,
                        'name' => $r->user?->name ?? 'Deleted user',
                        'designation' => $r->user?->designation,
                        'delivered_at' => $r->delivered_at?->toIso8601String(),
                        'read_at' => $r->read_at?->toIso8601String(),
                        'acknowledged_at' => $r->acknowledged_at?->toIso8601String(),
                    ])
                : null,
            'canViewHistory' => $user->can('viewAny', PriorityBroadcast::class),
        ]);
    }

    public function store(StorePriorityBroadcastRequest $request): RedirectResponse
    {
        $broadcast = $this->broadcasts->send(
            $request->user(),
            trim($request->string('title')->toString()),
            trim($request->string('message')->toString()),
            $request->expiresAtUtc(),
        );

        return back()->with('success', "Priority message sent to {$broadcast->recipients_count} ".str('user')->plural($broadcast->recipients_count).'.');
    }

    public function read(Request $request, PriorityBroadcast $broadcast): JsonResponse
    {
        $this->broadcasts->markRead($this->recipientOrFail($request, $broadcast));

        return response()->json(['ok' => true, 'notifications_unread' => $request->user()->unreadNotifications()->count()]);
    }

    public function acknowledge(Request $request, PriorityBroadcast $broadcast): JsonResponse|RedirectResponse
    {
        $this->broadcasts->acknowledge($this->recipientOrFail($request, $broadcast));

        return $request->expectsJson() && ! $request->header('X-Inertia')
            ? response()->json(['ok' => true, 'notifications_unread' => $request->user()->unreadNotifications()->count()])
            : back()->with('success', 'Priority message acknowledged.');
    }

    /** Only the authenticated user's own recipient row; no user id is ever taken from input. */
    private function recipientOrFail(Request $request, PriorityBroadcast $broadcast): PriorityBroadcastRecipient
    {
        return PriorityBroadcastRecipient::where('broadcast_id', $broadcast->id)
            ->where('user_id', $request->user()->id)
            ->firstOr(fn () => abort(403));
    }

    /** @return array<string, mixed> */
    private function summary(PriorityBroadcast $b): array
    {
        return [
            'id' => $b->id,
            'title' => $b->title,
            'priority' => $b->priority,
            'sender' => $b->sender?->name,
            'sent_at' => $b->created_at?->toIso8601String(),
            'expires_at' => $b->expires_at?->toIso8601String(),
            'expired' => $b->isExpired(),
            'recipients_count' => $b->recipients_count,
            'read_count' => (int) ($b->read_count ?? 0),
            'acknowledged_count' => (int) ($b->acknowledged_count ?? 0),
        ];
    }
}
