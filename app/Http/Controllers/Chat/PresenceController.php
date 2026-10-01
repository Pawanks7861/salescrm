<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Services\Chat\ConversationService;
use App\Services\Chat\PresenceService;
use App\Services\Chat\PriorityBroadcastService;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Authenticated heartbeat from every open CRM tab. Records presence (write
 * throttled in PresenceService) and returns the caller's own chat unread total
 * and active priority messages; it never returns anyone else's presence.
 */
class PresenceController extends Controller
{
    public function heartbeat(
        Request $request,
        PresenceService $presence,
        ConversationService $conversations,
        PriorityBroadcastService $broadcasts,
    ): JsonResponse {
        $user = $request->user();
        $presence->touch($user);

        return response()->json([
            'chat_unread' => $user->hasPermission(Permissions::CHAT_USE) ? $conversations->totalUnread($user->id) : 0,
            'priority' => $broadcasts->activeFor($user),
        ]);
    }
}
