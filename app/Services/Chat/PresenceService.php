<?php

namespace App\Services\Chat;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Presence is derived from users.last_seen_at; nothing stores an "is online"
 * flag that could go stale when a browser closes without logging out.
 */
class PresenceService
{
    /** A user counts as online when seen within this window. */
    public const ONLINE_SECONDS = 120;

    /** last_seen_at is written at most this often per user. */
    public const WRITE_INTERVAL_SECONDS = 60;

    public function touch(User $user): void
    {
        $now = now();

        if ($user->last_seen_at && $user->last_seen_at->gt($now->copy()->subSeconds(self::WRITE_INTERVAL_SECONDS))) {
            return;
        }

        DB::table('users')->where('id', $user->id)->update(['last_seen_at' => $now]);
        $user->setAttribute('last_seen_at', $now);
        $user->syncOriginalAttribute('last_seen_at');
    }

    public static function isOnline(?CarbonInterface $lastSeen): bool
    {
        return $lastSeen !== null && $lastSeen->gt(now()->subSeconds(self::ONLINE_SECONDS));
    }

    /** @return array{online: bool, last_seen_at: ?string} */
    public static function present(User $user): array
    {
        return [
            'online' => (bool) $user->is_active && ! $user->trashed() && self::isOnline($user->last_seen_at),
            'last_seen_at' => $user->last_seen_at?->toIso8601String(),
        ];
    }
}
