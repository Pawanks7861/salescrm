<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Message;
use App\Services\Chat\MessageService;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Chat files are only reachable here. The attachment id alone proves nothing:
 * it must belong to a live chat message in a conversation the caller is in.
 * Anything else is a 404 so ids cannot be probed.
 */
class ChatAttachmentController extends Controller
{
    private const INLINE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public function show(Request $request, Attachment $attachment, MessageService $messages): StreamedResponse
    {
        $user = $request->user();

        abort_unless($attachment->attachable_type === (new Message)->getMorphClass(), 404);

        $message = Message::query()->with('conversation')->find($attachment->attachable_id);

        abort_unless(
            $message
            && $user->hasPermission(Permissions::CHAT_USE)
            && $message->conversation?->hasParticipant($user->id),
            404,
        );

        $inline = $request->boolean('inline') && in_array($attachment->mime_type, self::INLINE_MIMES, true);

        return $messages->download($attachment, $inline);
    }
}
