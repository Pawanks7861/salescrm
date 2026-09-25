<?php

namespace App\Services\Reminders;

use App\Support\ReminderState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The single idempotent reminder queue used by follow-ups and meetings. Each
 * alert is one row whose status is the guard:
 *
 *   pending ──claimDue()──▶ processing ──job──▶ sent
 *      ▲                        │
 *      └──── release() (retry) ─┘──▶ failed (after MAX_ATTEMPTS)
 *
 * claimDue() flips pending → processing with a conditional UPDATE per row, so
 * overlapping scheduler runs can never both dispatch the same reminder. Rows
 * stuck in processing (worker crash) are reclaimed after a grace period.
 */
class ReminderQueue
{
    /**
     * @param  class-string<Model>  $model
     * @return Collection<int, int> ids now owned by the caller
     */
    public function claimDue(string $model, int $limit = 200): Collection
    {
        $now = now();

        $model::query()
            ->where('status', ReminderState::PROCESSING)
            ->where('claimed_at', '<', $now->copy()->subMinutes(ReminderState::STALE_PROCESSING_MINUTES))
            ->update(['status' => ReminderState::PENDING, 'updated_at' => $now]);

        $candidates = $model::query()
            ->where('status', ReminderState::PENDING)
            ->where('remind_at', '<=', $now)
            ->orderBy('remind_at')
            ->limit($limit)
            ->pluck('id');

        return $candidates->filter(fn (int $id) => $model::query()
            ->whereKey($id)
            ->where('status', ReminderState::PENDING)
            ->update([
                'status' => ReminderState::PROCESSING,
                'claimed_at' => $now,
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => $now,
            ]) === 1)->values();
    }

    public function markSent(Model $reminder): void
    {
        $reminder->forceFill(['status' => ReminderState::SENT, 'sent_at' => now(), 'last_error' => null])->save();
    }

    /** No longer relevant (record closed, access lost…). */
    public function discard(Model $reminder): void
    {
        $reminder->forceFill(['status' => ReminderState::CANCELLED])->save();
    }

    /** Returns the row to the queue for another attempt, or gives up. */
    public function release(Model $reminder, string $error): void
    {
        $failed = $reminder->attempts >= ReminderState::MAX_ATTEMPTS;

        $reminder->forceFill([
            'status' => $failed ? ReminderState::FAILED : ReminderState::PENDING,
            'claimed_at' => null,
            'last_error' => mb_substr($error, 0, 500),
        ]);

        if ($failed && $reminder->hasCast('failed_at')) {
            $reminder->failed_at = now();
        }

        $reminder->save();
    }
}
