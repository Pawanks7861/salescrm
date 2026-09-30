<?php

namespace App\Services\Notifications;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Firebase Cloud Messaging HTTP v1. Builds a short-lived Google OAuth token
 * from the service account and posts a message the browser can display.
 * Nothing here is written to the log: responses and exceptions can contain
 * the device token or the signed assertion.
 */
class FcmClient
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public function configured(): bool
    {
        return filled(config('fcm.project_id'))
            && filled(config('fcm.client_email'))
            && filled(config('fcm.private_key'));
    }

    /**
     * @param  array{id: string, event: string, title: string, body: string, url: string}  $payload
     * @return 'sent'|'drop'|'retry'
     */
    public function send(string $deviceToken, array $payload): string
    {
        try {
            $data = [
                'id' => (string) $payload['id'],
                'event' => (string) $payload['event'],
                'title' => (string) $payload['title'],
                'body' => (string) $payload['body'],
                'url' => (string) $payload['url'],
            ];
            $response = Http::withToken($this->accessToken())
                ->acceptJson()
                ->timeout(10)
                ->post($this->endpoint(), [
                    'message' => [
                        'token' => $deviceToken,
                        'data' => $data,
                        'webpush' => [
                            'headers' => [
                                'Urgency' => 'high',
                                'TTL' => '86400',
                            ],
                            'notification' => [
                                'title' => $data['title'],
                                'body' => $data['body'],
                                'tag' => $data['id'],
                            ],
                            'fcm_options' => [
                                'link' => self::absoluteLink($data['url']),
                            ],
                        ],
                    ],
                ]);
        } catch (\Throwable) {
            return 'retry';
        }

        if ($response->successful()) {
            return 'sent';
        }

        if ($response->status() === 401) {
            Cache::forget($this->cacheKey());

            return 'retry';
        }

        return $this->shouldDrop($response) ? 'drop' : 'retry';
    }

    public function accessToken(): string
    {
        $cached = Cache::get($this->cacheKey());
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $this->assertion(),
            ]);
        } catch (\Throwable $e) {
            throw new RuntimeException('FCM authentication failed', 0, $e);
        }

        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new RuntimeException('FCM authentication failed');
        }

        Cache::put($this->cacheKey(), $token, now()->addMinutes(50));

        return $token;
    }

    /** FCM opens this URL when the notification is clicked. It must be absolute. */
    public static function absoluteLink(string $url): string
    {
        if (str_starts_with($url, 'https://') || str_starts_with($url, 'http://')) {
            return $url;
        }

        return rtrim((string) config('app.url'), '/').'/'.ltrim($url, '/');
    }

    private function endpoint(): string
    {
        return 'https://fcm.googleapis.com/v1/projects/'.rawurlencode((string) config('fcm.project_id')).'/messages:send';
    }

    private function cacheKey(): string
    {
        return 'fcm:access:'.hash('sha256', (string) config('fcm.client_email'));
    }

    private function assertion(): string
    {
        $now = time();
        $unsigned = $this->b64(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$this->b64([
            'iss' => (string) config('fcm.client_email'),
            'scope' => self::SCOPE,
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]);

        $key = str_replace('\\n', "\n", (string) config('fcm.private_key'));
        if (! openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('FCM signing failed');
        }

        return $unsigned.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }

    private function b64(array $data): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($data, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    }

    private function shouldDrop(Response $response): bool
    {
        if (in_array($response->status(), [400, 404], true)) {
            return true;
        }

        $status = (string) $response->json('error.status');
        $code = (string) data_get($response->json(), 'error.details.0.errorCode');

        return in_array($status, ['UNREGISTERED', 'INVALID_ARGUMENT', 'NOT_FOUND', 'SENDER_ID_MISMATCH'], true)
            || in_array($code, ['UNREGISTERED', 'INVALID_ARGUMENT', 'SENDER_ID_MISMATCH'], true);
    }
}
