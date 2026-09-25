<?php

namespace App\Services\Notifications;

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\BrandingService;
use App\Services\SettingService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Browser push on top of the in-app notification system. Delivery requires
 * VAPID keys, the global switch (notifications.browser_enabled), an active
 * user who opted in, and at least one subscribed browser. Endpoints and keys
 * are never logged; only subscription ids and HTTP status codes are.
 */
class WebPushService
{
    /** Push services a subscription endpoint may point at (prevents server-side requests to arbitrary hosts). */
    public const ALLOWED_HOST_SUFFIXES = [
        'fcm.googleapis.com', 'android.googleapis.com', 'push.services.mozilla.com',
        'notify.windows.com', 'push.apple.com',
    ];

    public function __construct(
        private readonly SettingService $settings,
        private readonly PushTransport $transport,
    ) {}

    public function isConfigured(): bool
    {
        return filled(config('webpush.vapid.public_key')) && filled(config('webpush.vapid.private_key'));
    }

    public function globallyEnabled(): bool
    {
        return (bool) $this->settings->get('notifications.browser_enabled', true);
    }

    public function soundGloballyEnabled(): bool
    {
        return (bool) $this->settings->get('notifications.sound_enabled', true);
    }

    public function shouldPush(User $user): bool
    {
        return $this->isConfigured()
            && $this->globallyEnabled()
            && $user->is_active
            && $user->browser_notifications_enabled
            && $user->pushSubscriptions()->exists();
    }

    public static function allowedEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])) {
            return false;
        }
        $host = strtolower($parts['host']);

        foreach (self::ALLOWED_HOST_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Registers (or re-assigns) this browser's subscription to the signed-in
     * user. The owner is always the authenticated user, never request input.
     * Other browsers of the same user are left untouched.
     */
    public function subscribe(User $user, string $endpoint, string $publicKey, string $authToken, string $encoding, ?string $userAgent): PushSubscription
    {
        $hash = PushSubscription::hashEndpoint($endpoint);
        $subscription = PushSubscription::query()->firstOrNew(['endpoint_hash' => $hash]);
        $subscription->forceFill([
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'public_key' => $publicKey,
            'auth_token' => $authToken,
            'content_encoding' => $encoding,
            'user_agent' => $userAgent ? Str::limit($userAgent, 250, '') : null,
        ])->save();

        return $subscription;
    }

    /** Removes the caller's own subscription for this endpoint; returns whether one existed. */
    public function unsubscribe(User $user, string $endpoint): bool
    {
        return PushSubscription::query()
            ->where('user_id', $user->id)
            ->where('endpoint_hash', PushSubscription::hashEndpoint($endpoint))
            ->delete() > 0;
    }

    /**
     * @param  array{id: string, event: string, title: string, body: string, url: string}  $payload
     * @return int number of browsers that accepted the message
     */
    public function send(User $user, array $payload): int
    {
        return count($this->deliver($user, $payload)->delivered);
    }

    /**
     * Sends to every subscribed browser of the user except `$skip` (browsers
     * that already received this notification on an earlier attempt).
     *
     * @param  array{id: string, event: string, title: string, body: string, url: string}  $payload
     * @param  list<int>  $skip
     */
    public function deliver(User $user, array $payload, array $skip = []): PushDeliveryResult
    {
        $subscriptions = $user->pushSubscriptions()->whereKeyNot($skip)->get();
        if ($subscriptions->isEmpty()) {
            return new PushDeliveryResult;
        }

        $message = json_encode([
            ...$payload,
            'icon' => $this->icon(),
            'sound' => $this->soundGloballyEnabled() && $user->notification_sound_enabled,
            'ts' => now()->getTimestampMs(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        try {
            $results = $this->transport->send($subscriptions, $message);
        } catch (PushEncryptionUnavailable $e) {
            Log::error('Browser push cannot be encrypted on this server: set OPENSSL_CONF for the queue worker (see docs/BROWSER_NOTIFICATIONS.md)', ['user_id' => $user->id, 'notification_id' => $payload['id']]);

            return new PushDeliveryResult;
        } catch (\Throwable $e) {
            Log::warning('Browser push delivery failed', ['user_id' => $user->id, 'notification_id' => $payload['id'], 'error' => class_basename($e)]);

            return new PushDeliveryResult(retry: $subscriptions->modelKeys());
        }

        $delivered = $retry = [];
        $removed = 0;
        foreach ($subscriptions as $subscription) {
            $result = $results[$subscription->id] ?? null;
            $status = $result['status'] ?? null;

            if ($result !== null && $result['ok']) {
                $delivered[] = $subscription->id;
                $subscription->forceFill(['last_used_at' => now()])->saveQuietly();
            } elseif ($result !== null && $result['expired']) {
                $subscription->delete();
                $removed++;
            } elseif ($result === null || $status === null || $status === 429 || $status >= 500) {
                $retry[] = $subscription->id;
            } else {
                Log::warning('Browser push rejected', ['user_id' => $user->id, 'subscription_id' => $subscription->id, 'status' => $status]);
            }
        }

        return new PushDeliveryResult($delivered, $retry, $removed);
    }

    /** Notification icon: the company logo (raster only), else the built-in CRM icon. */
    public function icon(): string
    {
        $logo = app(BrandingService::class)->url('logo');

        return $logo ?? '/images/notification-icon.png';
    }
}
