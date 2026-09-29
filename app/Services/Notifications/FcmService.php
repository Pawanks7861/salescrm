<?php

namespace App\Services\Notifications;

use App\Models\FcmToken;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Registers device tokens and delivers the same payload the browser push
 * uses. A failed send never includes the token in the log.
 */
class FcmService
{
    public const MAX_PER_USER = 10;

    public function __construct(
        private readonly FcmClient $client,
        private readonly SettingService $settings,
    ) {}

    public function isConfigured(): bool
    {
        return $this->client->configured();
    }

    /**
     * Public Firebase web config for the browser. Null unless the server
     * credentials and the web config are both complete. The private key is
     * not part of this array.
     *
     * @return array{apiKey: string, authDomain: string, projectId: string, messagingSenderId: string, appId: string, vapidKey: string}|null
     */
    public function webConfig(): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $web = (array) config('fcm.web');
        foreach (['api_key', 'project_id', 'messaging_sender_id', 'app_id', 'vapid_key'] as $key) {
            if (! filled($web[$key] ?? null)) {
                return null;
            }
        }

        $projectId = (string) $web['project_id'];

        return [
            'apiKey' => (string) $web['api_key'],
            'authDomain' => filled($web['auth_domain'] ?? null) ? (string) $web['auth_domain'] : $projectId.'.firebaseapp.com',
            'projectId' => $projectId,
            'messagingSenderId' => (string) $web['messaging_sender_id'],
            'appId' => (string) $web['app_id'],
            'vapidKey' => (string) $web['vapid_key'],
        ];
    }

    public function globallyEnabled(): bool
    {
        return (bool) $this->settings->get('notifications.browser_enabled', true);
    }

    public function shouldSend(User $user): bool
    {
        return $this->isConfigured()
            && $this->globallyEnabled()
            && $user->is_active
            && $user->browser_notifications_enabled
            && $user->fcmTokens()->exists();
    }

    public function register(User $user, string $token, ?string $userAgent): FcmToken
    {
        $row = FcmToken::query()->firstOrNew(['token_hash' => FcmToken::hashToken($token)]);
        $row->forceFill([
            'user_id' => $user->id,
            'token' => $token,
            'user_agent' => $userAgent ? Str::limit($userAgent, 250, '') : null,
            'last_used_at' => now(),
        ])->save();

        $overflow = FcmToken::query()
            ->where('user_id', $user->id)
            ->where('id', '!=', $row->id)
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->skip(self::MAX_PER_USER - 1)
            ->pluck('id');

        if ($overflow->isNotEmpty()) {
            FcmToken::query()->whereIn('id', $overflow)->delete();
        }

        return $row;
    }

    public function unregister(User $user, string $token): bool
    {
        return FcmToken::query()
            ->where('user_id', $user->id)
            ->where('token_hash', FcmToken::hashToken($token))
            ->delete() > 0;
    }

    /**
     * @param  array{id: string, event: string, title: string, body: string, url: string}  $payload
     * @param  list<int>  $skip
     */
    public function deliver(User $user, array $payload, array $skip = []): PushDeliveryResult
    {
        $delivered = [];
        $retry = [];
        $removed = 0;

        $tokens = $user->fcmTokens()->when($skip !== [], fn ($q) => $q->whereNotIn('id', $skip))->get();

        foreach ($tokens as $row) {
            try {
                $outcome = $this->client->send((string) $row->token, $payload);
            } catch (\Throwable $e) {
                Log::warning('FCM send failed', [
                    'user_id' => $user->id,
                    'notification_id' => $payload['id'],
                    'error' => class_basename($e),
                ]);
                $retry[] = $row->id;

                continue;
            }

            if ($outcome === 'sent') {
                $row->forceFill(['last_used_at' => now()])->save();
                $delivered[] = $row->id;
            } elseif ($outcome === 'drop') {
                $row->delete();
                $removed++;
            } else {
                $retry[] = $row->id;
            }
        }

        return new PushDeliveryResult($delivered, $retry, $removed);
    }
}
