<?php

namespace App\Services\Meetings;

use App\Models\Meeting;
use App\Models\MeetingReminder;
use App\Services\Reminders\ReminderQueue;
use App\Support\MeetingReminderOptions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Plans meeting reminders: one `meeting_reminders` row per (offset, recipient).
 * Recipients are the host plus internal participants who have not declined.
 * Claiming / delivery state goes through the shared ReminderQueue, exactly
 * like follow-up reminders.
 */
class MeetingReminderService
{
    public function __construct(private readonly ReminderQueue $queue) {}

    /** (Re)plans reminder rows after a meeting is created, edited, restored, or its attendees change. */
    public function schedule(Meeting $meeting): void
    {
        $desired = $meeting->isUpcoming() && ! $meeting->trashed() ? $this->desired($meeting) : [];
        $key = fn (int $userId, CarbonImmutable|\DateTimeInterface $at) => $userId.'|'.CarbonImmutable::instance($at)->toDateTimeString();
        $keys = array_keys($desired);

        $meeting->reminders()->where('status', MeetingReminder::PENDING)->get()
            ->reject(fn (MeetingReminder $r) => in_array($key((int) $r->user_id, $r->remind_at), $keys, true))
            ->each(fn (MeetingReminder $r) => $r->forceFill(['status' => MeetingReminder::CANCELLED])->save());

        foreach ($desired as $d) {
            $existing = $meeting->reminders()
                ->where('user_id', $d['user_id'])
                ->where('channel', MeetingReminder::CHANNEL_DATABASE)
                ->where('remind_at', $d['at']->toDateTimeString())
                ->first();

            if (! $existing) {
                $meeting->reminders()->forceCreate([
                    'user_id' => $d['user_id'],
                    'minutes_before' => $d['minutes'],
                    'remind_at' => $d['at'],
                    'channel' => MeetingReminder::CHANNEL_DATABASE,
                    'status' => MeetingReminder::PENDING,
                ]);
            } elseif ($existing->status === MeetingReminder::CANCELLED) {
                $existing->forceFill(['status' => MeetingReminder::PENDING, 'minutes_before' => $d['minutes'], 'attempts' => 0, 'claimed_at' => null, 'last_error' => null])->save();
            }
        }
    }

    public function cancel(Meeting $meeting): void
    {
        $meeting->reminders()->where('status', MeetingReminder::PENDING)->update(['status' => MeetingReminder::CANCELLED, 'updated_at' => now()]);
    }

    /** @return Collection<int, int> */
    public function claimDue(int $limit = 200): Collection
    {
        return $this->queue->claimDue(MeetingReminder::class, $limit);
    }

    public function markSent(MeetingReminder $reminder): void
    {
        $this->queue->markSent($reminder);
    }

    public function discard(MeetingReminder $reminder): void
    {
        $this->queue->discard($reminder);
    }

    public function release(MeetingReminder $reminder, string $error): void
    {
        $this->queue->release($reminder, $error);
    }

    /** @return array<int> sanitised, unique offsets (minutes before start) */
    public static function normalizeOffsets(mixed $offsets): array
    {
        $values = collect((array) $offsets)
            ->filter(fn ($v) => $v !== null && $v !== '' && is_numeric($v))
            ->map(fn ($v) => (int) $v)
            ->filter(fn (int $v) => array_key_exists($v, MeetingReminderOptions::OPTIONS))
            ->unique()
            ->sortDesc()
            ->values()
            ->all();

        return $values;
    }

    /** @return array<string, array{user_id: int, minutes: int, at: CarbonImmutable}> keyed "user|at" */
    private function desired(Meeting $meeting): array
    {
        $offsets = self::normalizeOffsets($meeting->reminder_offsets ?? []);
        if ($offsets === []) {
            return [];
        }

        $now = CarbonImmutable::now()->startOfSecond();
        $start = CarbonImmutable::instance($meeting->start_at);
        // Latest reminder already delivered (or in flight) per user, for this start time.
        $lastSent = $meeting->reminders()
            ->whereIn('status', [MeetingReminder::SENT, MeetingReminder::PROCESSING])
            ->get(['user_id', 'remind_at'])
            ->groupBy('user_id')
            ->map(fn (Collection $rows) => $rows->max('remind_at'));

        $desired = [];
        foreach ($meeting->attendeeUserIds() as $userId) {
            foreach ($offsets as $minutes) {
                $at = $start->subMinutes($minutes);

                if ($at->lt($now)) {
                    // Window already passed but the meeting is still ahead: remind
                    // once, now — unless a reminder already went out at or after
                    // this window (it covers this offset too).
                    $sent = $lastSent->get($userId);
                    if ($start->lte($now) || ($sent && $at->lte($sent))) {
                        continue;
                    }
                    $at = $now;
                }

                $desired[$userId.'|'.$at->toDateTimeString()] ??= ['user_id' => $userId, 'minutes' => $minutes, 'at' => $at];
            }
        }

        return $desired;
    }
}
