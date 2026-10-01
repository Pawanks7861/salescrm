<?php

namespace App\Services\Chat;

use App\Enums\AuditAction;
use App\Jobs\DeliverPriorityBroadcast;
use App\Models\PriorityBroadcast;
use App\Models\PriorityBroadcastRecipient;
use App\Models\User;
use App\Notifications\Chat\PriorityBroadcastNotification;
use App\Services\AuditService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class PriorityBroadcastService
{
    public const CHUNK = 100;

    public const ACTIVE_LIMIT = 5;

    public function __construct(private readonly AuditService $audit) {}

    /**
     * Snapshots every active user except the sender as a recipient in the same
     * transaction as the broadcast; delivery (database notification + push)
     * happens in queued chunks only after that transaction commits.
     */
    public function send(User $sender, string $title, string $message, ?CarbonInterface $expiresAt): PriorityBroadcast
    {
        $broadcast = DB::transaction(function () use ($sender, $title, $message, $expiresAt) {
            $broadcast = PriorityBroadcast::create([
                'title' => $title,
                'message' => $message,
                'priority' => PriorityBroadcast::PRIORITY_URGENT,
                'sent_by' => $sender->id,
                'expires_at' => $expiresAt,
            ]);

            $now = now();

            DB::table('priority_broadcast_recipients')->insertUsing(
                ['broadcast_id', 'user_id', 'created_at', 'updated_at'],
                User::query()->active()->whereKeyNot($sender->id)
                    ->selectRaw('?, id, ?, ?', [$broadcast->id, $now, $now])
                    ->toBase()
            );

            $count = PriorityBroadcastRecipient::where('broadcast_id', $broadcast->id)->count();
            $broadcast->forceFill(['recipients_count' => $count])->save();

            $this->audit->log(AuditAction::PriorityBroadcastSent, 'chat', $broadcast, "Priority message sent: {$broadcast->title}", null, [
                'title' => $broadcast->title,
                'recipients' => $count,
                'expires_at' => $broadcast->expires_at?->toIso8601String(),
            ]);

            return $broadcast;
        });

        PriorityBroadcastRecipient::where('broadcast_id', $broadcast->id)
            ->orderBy('user_id')
            ->pluck('user_id')
            ->chunk(self::CHUNK)
            ->each(fn ($userIds) => DeliverPriorityBroadcast::dispatch($broadcast->id, $userIds->values()->all()));

        return $broadcast;
    }

    /**
     * Delivers to one chunk of recipients. Safe to retry: rows already marked
     * delivered are skipped and the notification id is deterministic.
     *
     * @param  array<int>  $userIds
     */
    public function deliver(PriorityBroadcast $broadcast, array $userIds): void
    {
        if ($broadcast->isExpired()) {
            return;
        }

        $recipients = PriorityBroadcastRecipient::query()
            ->where('broadcast_id', $broadcast->id)
            ->whereIn('user_id', $userIds)
            ->whereNull('delivered_at')
            ->with('user')
            ->get();

        foreach ($recipients as $recipient) {
            $user = $recipient->user;

            if (! $user || $user->trashed() || ! $user->is_active) {
                continue;
            }

            $notificationId = PriorityBroadcastNotification::idFor($broadcast->id, $user->id);

            if (! DB::table('notifications')->where('id', $notificationId)->exists()) {
                try {
                    $user->notify(new PriorityBroadcastNotification($broadcast, $user->id));
                } catch (\Throwable $e) {
                    report($e);

                    continue;
                }
            }

            $recipient->forceFill(['delivered_at' => now()])->save();
        }
    }

    public function markRead(PriorityBroadcastRecipient $recipient): void
    {
        if ($recipient->read_at === null) {
            $recipient->forceFill(['read_at' => now()])->save();
        }
    }

    public function acknowledge(PriorityBroadcastRecipient $recipient): void
    {
        if ($recipient->acknowledged_at !== null) {
            return;
        }

        DB::transaction(function () use ($recipient) {
            $now = now();
            $recipient->forceFill([
                'read_at' => $recipient->read_at ?? $now,
                'acknowledged_at' => $now,
            ])->save();

            $broadcast = $recipient->broadcast;

            DB::table('notifications')
                ->where('id', PriorityBroadcastNotification::idFor($recipient->broadcast_id, $recipient->user_id))
                ->whereNull('read_at')
                ->update(['read_at' => $now]);

            $this->audit->log(AuditAction::PriorityBroadcastAcknowledged, 'chat', $broadcast, "Priority message acknowledged: {$broadcast->title}", null, [
                'broadcast_id' => $broadcast->id,
            ]);
        });
    }

    /**
     * Unacknowledged, unexpired broadcasts for the persistent banner.
     *
     * @return array<int, array<string, mixed>>
     */
    public function activeFor(User $user): array
    {
        return PriorityBroadcast::query()
            ->active()
            ->join('priority_broadcast_recipients as r', 'r.broadcast_id', '=', 'priority_broadcasts.id')
            ->where('r.user_id', $user->id)
            ->whereNull('r.acknowledged_at')
            ->with('sender:id,name')
            ->orderByDesc('priority_broadcasts.id')
            ->limit(self::ACTIVE_LIMIT)
            ->get(['priority_broadcasts.*', 'r.read_at as recipient_read_at'])
            ->map(fn (PriorityBroadcast $b) => [
                'id' => $b->id,
                'title' => $b->title,
                'message' => $b->message,
                'priority' => $b->priority,
                'sender' => $b->sender?->name,
                'sent_at' => $b->created_at?->toIso8601String(),
                'expires_at' => $b->expires_at?->toIso8601String(),
                'read' => $b->recipient_read_at !== null,
            ])
            ->all();
    }
}
