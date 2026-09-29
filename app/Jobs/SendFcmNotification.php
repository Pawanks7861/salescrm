<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Notifications\FcmService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Sends one notification to every FCM token the user registered. Unique per
 * notification id. Tokens that already received it are skipped. Invalid
 * tokens are deleted. A failure here never affects the in-app notification.
 */
class SendFcmNotification implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const RETRY_DELAYS = [30, 120];

    public int $tries = 3;

    public int $uniqueFor = 86400;

    /** @param  array{id: string, event: string, title: string, body: string, url: string}  $payload */
    public function __construct(public int $userId, public array $payload) {}

    public function uniqueId(): string
    {
        return $this->payload['id'];
    }

    public static function deliveredKey(string $notificationId): string
    {
        return "fcm:delivered:{$notificationId}";
    }

    public function handle(FcmService $fcm): void
    {
        $user = User::query()->find($this->userId);
        if (! $user || ! $fcm->shouldSend($user)) {
            return;
        }

        $key = self::deliveredKey($this->payload['id']);
        $done = Cache::get($key, []);
        $result = $fcm->deliver($user, $this->payload, is_array($done) ? $done : []);

        if ($result->delivered !== []) {
            Cache::put($key, array_values(array_unique([...$done, ...$result->delivered])), now()->addDay());
        }

        if ($result->retry === []) {
            return;
        }

        $attempt = max(1, $this->attempts());
        if ($attempt < $this->tries) {
            $this->release(self::RETRY_DELAYS[$attempt - 1] ?? self::RETRY_DELAYS[array_key_last(self::RETRY_DELAYS)]);

            return;
        }

        Log::warning('FCM gave up after retries', [
            'user_id' => $user->id,
            'notification_id' => $this->payload['id'],
            'devices' => count($result->retry),
        ]);
    }
}
