<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Jobs\SendWebPushNotification;
use App\Notifications\Channels\WebPushChannel;
use App\Services\AuditService;
use App\Services\Notifications\WebPushService;
use App\Support\PushEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** The signed-in user's own browser-notification and sound preferences. */
class NotificationPreferenceController extends Controller
{
    public function update(Request $request, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'browser_notifications_enabled' => ['sometimes', 'boolean'],
            'notification_sound_enabled' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        $user->forceFill($data);
        $changes = $user->getDirty();
        $user->save();

        $events = [
            'browser_notifications_enabled' => [AuditAction::BrowserNotificationsEnabled, AuditAction::BrowserNotificationsDisabled, 'Browser notifications'],
            'notification_sound_enabled' => [AuditAction::NotificationSoundEnabled, AuditAction::NotificationSoundDisabled, 'Notification sound'],
        ];
        foreach (array_intersect_key($events, $changes) as $key => [$on, $off, $label]) {
            $audit->log($user->{$key} ? $on : $off, 'profile', $user, "{$user->name} ".($user->{$key} ? 'enabled' : 'disabled')." {$label}", [$key => ! $user->{$key}], [$key => $user->{$key}]);
        }

        return response()->json([
            'browser_notifications_enabled' => $user->browser_notifications_enabled,
            'notification_sound_enabled' => $user->notification_sound_enabled,
        ]);
    }

    /**
     * Sends a real Web Push of the chosen type to the caller's own browsers
     * only, optionally delayed so the tab can be backgrounded, minimised or
     * closed first. Not stored as an in-app notification.
     */
    public function test(Request $request, WebPushService $push): JsonResponse
    {
        $data = $request->validate([
            'event' => ['required', Rule::in(PushEvent::ALL)],
            'delay' => ['sometimes', 'integer', 'min:0', 'max:60'],
        ]);

        $user = $request->user();
        if (! $push->shouldPush($user)) {
            return response()->json(['message' => 'Enable browser notifications on this browser first.'], 422);
        }

        [$title, $body] = $data['event'] === PushEvent::NEW_LEAD_ASSIGNED
            ? ['New Lead Assigned', 'Test: a new lead has been assigned to you.']
            : ['Follow-up Reminder', 'Test: your follow-up is due now.'];

        $delay = (int) ($data['delay'] ?? 0);
        SendWebPushNotification::dispatch($user->id, WebPushChannel::payload(
            'test-'.Str::uuid(),
            $data['event'],
            $title,
            $body,
            route('profile.edit', [], false),
        ))->delay($delay > 0 ? now()->addSeconds($delay) : null);

        return response()->json(['queued' => true, 'delay' => $delay], 202);
    }
}
