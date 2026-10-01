<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\SendMessageRequest;
use App\Http\Requests\Chat\UpdateMessageRequest;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Chat\ChatPresenter;
use App\Services\Chat\ConversationService;
use App\Services\Chat\MessageService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatMessageController extends Controller
{
    public function __construct(
        private readonly MessageService $messages,
        private readonly ConversationService $conversations,
    ) {}

    /**
     * Without cursors: the latest page. ?before=id: older history.
     * ?after=id[&since=iso]: the live sync used while the conversation is open.
     */
    public function index(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $data = $request->validate([
            'before' => ['nullable', 'integer', 'min:1'],
            'after' => ['nullable', 'integer', 'min:0'],
            'since' => ['nullable', 'date'],
            'viewing' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $before = isset($data['before']) ? (int) $data['before'] : null;
        $after = isset($data['after']) ? (int) $data['after'] : null;
        $syncedAt = now();

        if ($request->boolean('viewing')) {
            $this->messages->markViewing($conversation, $user);
        }

        $page = $this->messages->page($conversation, $before, $after);
        $other = $conversation->participants()->where('user_id', '!=', $user->id)->with(['user.role:id,name,slug'])->first();

        $payload = [
            'messages' => $page->map(fn (Message $m) => ChatPresenter::message($m, $user->id))->values(),
            'has_more' => $after === null && $page->isNotEmpty()
                && Message::withTrashed()->where('conversation_id', $conversation->id)->where('id', '<', $page->first()->id)->exists(),
            'participant' => $other ? [
                'user' => ChatPresenter::user($other->user),
                'last_read_message_id' => $other->last_read_message_id,
                'typing' => $this->messages->isTyping($conversation, $other->user_id),
            ] : null,
            'can_send' => $other !== null && $this->conversations->canChatWith($user, $other->user),
            'synced_at' => $syncedAt->toIso8601String(),
        ];

        if ($after !== null && isset($data['since'])) {
            $payload['changed'] = $this->messages
                ->changedSince($conversation, CarbonImmutable::parse($data['since'])->subSeconds(2), $after)
                ->map(fn (Message $m) => ChatPresenter::message($m, $user->id))
                ->values();
        }

        return response()->json($payload);
    }

    public function store(SendMessageRequest $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        $other = $conversation->otherUser($user->id);

        abort_unless($other && $this->conversations->canChatWith($user, $other), 422, 'This user can no longer receive messages.');

        $message = $this->messages->send(
            $conversation,
            $user,
            $request->input('message'),
            $request->filled('reply_to_message_id') ? $request->integer('reply_to_message_id') : null,
            $request->file('attachments', []),
        );

        return response()->json(['message' => ChatPresenter::message($message, $user->id)], 201);
    }

    public function update(UpdateMessageRequest $request, Message $message): JsonResponse
    {
        $message = $this->messages->edit($message, $request->string('message')->toString());

        return response()->json(['message' => ChatPresenter::message($message->load(['attachments', 'replyTo']), $request->user()->id)]);
    }

    public function destroy(Request $request, Message $message): JsonResponse
    {
        $this->authorize('delete', $message);

        $this->messages->delete($message);

        return response()->json(['message' => ChatPresenter::message($message->refresh(), $request->user()->id)]);
    }

    public function read(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $user = $request->user();

        return response()->json([
            'last_read_message_id' => $this->conversations->markRead($conversation, $user),
            'unread_total' => $this->conversations->totalUnread($user->id),
            'notifications_unread' => $user->unreadNotifications()->count(),
        ]);
    }

    public function typing(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('send', $conversation);

        $this->messages->markTyping($conversation, $request->user());

        return response()->json(['ok' => true]);
    }
}
