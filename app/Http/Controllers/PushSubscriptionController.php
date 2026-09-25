<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Services\Notifications\WebPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A user registers / removes only the browser they are using, always for
 * themselves (the owner is the session user; any user id in the payload is
 * ignored). Responses never echo endpoints or keys.
 */
class PushSubscriptionController extends Controller
{
    public function __construct(private readonly WebPushService $push) {}

    public function store(Request $request): JsonResponse
    {
        abort_unless($this->push->isConfigured() && $this->push->globallyEnabled(), 409, 'Browser notifications are not available.');

        $data = $request->validate([
            'endpoint' => ['required', 'string', 'url', 'max:2048', function ($attribute, $value, $fail) {
                if (! WebPushService::allowedEndpoint((string) $value)) {
                    $fail('This push service is not supported.');
                }
            }],
            'keys.p256dh' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_\-=+\/]+$/'],
            'keys.auth' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_\-=+\/]+$/'],
            'content_encoding' => ['nullable', 'in:aes128gcm,aesgcm'],
            'replaces' => ['nullable', 'string', 'max:2048'],
        ]);

        if (! empty($data['replaces']) && $data['replaces'] !== $data['endpoint']) {
            $this->push->unsubscribe($request->user(), $data['replaces']);
        }

        $subscription = $this->push->subscribe(
            $request->user(),
            $data['endpoint'],
            $data['keys']['p256dh'],
            $data['keys']['auth'],
            $data['content_encoding'] ?? 'aes128gcm',
            $request->userAgent(),
        );
        $request->session()->put('push_subscription_hash', $subscription->endpoint_hash);

        return response()->json(['subscribed' => true], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2048']]);

        $removed = $this->push->unsubscribe($request->user(), $data['endpoint']);
        if ($request->session()->get('push_subscription_hash') === PushSubscription::hashEndpoint($data['endpoint'])) {
            $request->session()->forget('push_subscription_hash');
        }

        return response()->json(['removed' => $removed]);
    }
}
