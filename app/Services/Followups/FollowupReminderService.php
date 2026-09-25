<?php

namespace App\Services\Followups;

use App\Models\Followup;
use App\Models\FollowupReminder;
use App\Services\Reminders\ReminderQueue;
use App\Services\SettingService;
use App\Support\ReminderState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Plans follow-up reminders. Each alert is one `followup_reminders` row;
 * claiming and delivery state go through the shared ReminderQueue.
 */
class FollowupReminderService
{
    public const STALE_PROCESSING_MINUTES = ReminderState::STALE_PROCESSING_MINUTES;

    public function __construct(private readonly SettingService $settings, private readonly ReminderQueue $queue) {}

    /** (Re)plans reminder rows after a follow-up is created, edited, rescheduled or restored. */
    public function schedule(Followup $followup): void
    {
        $desired = $followup->isPending() && ! $followup->trashed() ? $this->desired($followup) : [];
        $keys = array_map(fn (array $d) => $d['kind'].'|'.$d['at']->toDateTimeString(), $desired);

        $followup->reminders()->where('status', FollowupReminder::PENDING)->get()
            ->reject(fn (FollowupReminder $r) => in_array($r->kind.'|'.$r->remind_at->toDateTimeString(), $keys, true))
            ->each(fn (FollowupReminder $r) => $r->forceFill(['status' => FollowupReminder::CANCELLED])->save());

        foreach ($desired as $d) {
            $existing = $followup->reminders()
                ->where('kind', $d['kind'])
                ->where('remind_at', $d['at']->toDateTimeString())
                ->first();

            if (! $existing) {
                $followup->reminders()->forceCreate([
                    'kind' => $d['kind'],
                    'remind_at' => $d['at'],
                    'status' => FollowupReminder::PENDING,
                ]);
            } elseif ($existing->status === FollowupReminder::CANCELLED) {
                $existing->forceFill(['status' => FollowupReminder::PENDING, 'attempts' => 0, 'claimed_at' => null, 'last_error' => null])->save();
            }
        }
    }

    public function cancel(Followup $followup): void
    {
        $followup->reminders()->where('status', FollowupReminder::PENDING)->update(['status' => FollowupReminder::CANCELLED, 'updated_at' => now()]);
    }

    /**
     * Atomically claims due reminders. Returns the ids this caller now owns.
     *
     * @return Collection<int, int>
     */
    public function claimDue(int $limit = 200): Collection
    {
        return $this->queue->claimDue(FollowupReminder::class, $limit);
    }

    public function markSent(FollowupReminder $reminder): void
    {
        $this->queue->markSent($reminder);
    }

    /** No longer relevant (follow-up closed, lead access lost…). */
    public function discard(FollowupReminder $reminder): void
    {
        $this->queue->discard($reminder);
    }

    /** Returns the row to the queue for another attempt, or gives up. */
    public function release(FollowupReminder $reminder, string $error): void
    {
        $this->queue->release($reminder, $error);
    }

    /** @return array<int, array{kind: string, at: CarbonImmutable}> */
    private function desired(Followup $followup): array
    {
        $now = CarbonImmutable::now();
        $scheduled = CarbonImmutable::instance($followup->scheduled_at);
        $desired = [];

        if ($followup->reminder_minutes_before !== null) {
            $at = $scheduled->subMinutes($followup->reminder_minutes_before);

            if ($at->lt($now)) {
                // Reminder window already passed but the follow-up is still ahead:
                // remind now, once. Never re-send if one already went out.
                $alreadySent = $followup->reminders()->where('kind', FollowupReminder::KIND_REMINDER)
                    ->whereIn('status', [FollowupReminder::SENT, FollowupReminder::PROCESSING])->exists();
                $at = $scheduled->gt($now) && ! $alreadySent ? $now->startOfSecond() : null;
            }

            if ($at) {
                $desired[] = ['kind' => FollowupReminder::KIND_REMINDER, 'at' => $at];
            }
        }

        $overdueAfter = (int) $this->settings->get('followup.overdue_alert_after_minutes', 60);
        if ($overdueAfter > 0) {
            $desired[] = ['kind' => FollowupReminder::KIND_OVERDUE, 'at' => $scheduled->addMinutes($overdueAfter)];
        }

        return $desired;
    }
}
