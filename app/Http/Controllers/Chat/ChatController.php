<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Chat\ChatPresenter;
use App\Services\Chat\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChatController extends Controller
{
    public function __construct(private readonly ConversationService $conversations) {}

    public function index(Request $request): Response
    {
        return $this->page($request, null);
    }

    public function show(Request $request, Conversation $conversation): Response
    {
        $this->authorize('view', $conversation);

        return $this->page($request, $conversation);
    }

    /** Conversation list refresh (polled by the open chat page). */
    public function conversations(Request $request): JsonResponse
    {
        $user = $request->user();
        $include = $request->integer('include') ?: null;

        if ($include && ! Conversation::whereKey($include)->forUser($user->id)->exists()) {
            $include = null;
        }

        return response()->json([
            'conversations' => $this->conversations->listFor($user, $include),
            'unread_total' => $this->conversations->totalUnread($user->id),
        ]);
    }

    /** Server-side, paginated search over chat-enabled active colleagues. */
    public function users(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        return response()->json([
            'users' => $this->conversations->searchUsers($request->user(), $data['q'] ?? null)
                ->map(fn (User $u) => ChatPresenter::user($u))
                ->values(),
        ]);
    }

    /** "New chat": opens the existing direct conversation or creates it. */
    public function start(Request $request, User $user): JsonResponse
    {
        $me = $request->user();

        abort_if($me->id === $user->id, 422, 'You cannot start a chat with yourself.');
        abort_unless($this->conversations->canChatWith($me, $user), 404);

        $conversation = $this->conversations->findOrCreateDirect($me, $user);

        return response()->json([
            'conversation_id' => $conversation->id,
            'url' => route('chat.show', $conversation, false),
        ]);
    }

    private function page(Request $request, ?Conversation $active): Response
    {
        $user = $request->user();

        return Inertia::render('Chat/Index', [
            'conversations' => $this->conversations->listFor($user, $active?->id),
            'activeId' => $active?->id,
            'config' => [
                'me' => $user->id,
                'maxAttachmentKb' => (int) config('crm.chat.max_attachment_kb'),
                'maxAttachments' => (int) config('crm.chat.max_attachments'),
                'maxLength' => (int) config('crm.chat.max_message_length'),
                'allowedExtensions' => config('crm.chat.allowed_extensions'),
            ],
        ]);
    }
}
