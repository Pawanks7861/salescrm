<?php

namespace App\Services\Telephony;

use App\Enums\CallRecordingStatus;
use App\Enums\CallStatus;
use App\Jobs\Telephony\ProcessCallRecording;
use App\Models\Call;
use App\Models\CallRecording;
use App\Services\Telephony\Data\ProviderCallEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Recovers calls whose final callback never arrived and recordings that were
 * not ready when the call ended. Bounded per run; provider look-ups happen
 * outside DB transactions; a provider outage stops the run early.
 */
class TelephonyReconciliationService
{
    public function __construct(
        private readonly TelephonyManager $telephony,
        private readonly CallLifecycleService $lifecycle,
        private readonly CallRecordingService $recordings,
    ) {}

    /** @return array{checked: int, updated: int, closed: int, recordings: int} */
    public function run(?int $limit = null): array
    {
        $limit ??= (int) config('telephony.reconcile.batch_size', 100);
        $stats = ['checked' => 0, 'updated' => 0, 'closed' => 0, 'recordings' => 0];
        $provider = $this->telephony->provider();
        $staleBefore = now()->subMinutes((int) config('telephony.reconcile.stale_after_minutes', 10));
        $abandonBefore = now()->subHours((int) config('telephony.reconcile.abandon_after_hours', 24));

        $calls = Call::query()
            ->where('provider', $provider->name())
            ->whereIn('status', CallStatus::openValues())
            ->where('started_at', '<', $staleBefore)
            ->orderBy('reconciled_at')
            ->orderBy('started_at')
            ->limit($limit)
            ->get();

        foreach ($calls as $call) {
            $stats['checked']++;

            if ($call->provider_call_id === null) {
                // Browser call that was never dialled / never reported by the provider.
                $this->applyLocked($call, $this->synthetic($call, CallStatus::Cancelled, 'not_dialled'));
                $stats['closed']++;

                continue;
            }

            try {
                $event = $provider->getCall($call->provider_call_id);
            } catch (TelephonyException $e) {
                Log::warning('Telephony reconciliation stopped', ['call_id' => $call->id, 'category' => $e->category]);
                $this->touch($call);
                break;
            }

            if ($event?->status && $event->status->isTerminal()) {
                $this->applyLocked($call, $event) ? $stats['updated']++ : null;
            } elseif ($call->started_at->lt($abandonBefore)) {
                $this->applyLocked($call, $this->synthetic($call, CallStatus::Failed, 'reconcile_timeout'));
                $stats['closed']++;
            } else {
                $this->touch($call);
            }
        }

        $stats['recordings'] = $this->pendingRecordings($limit);

        return $stats;
    }

    private function pendingRecordings(int $limit): int
    {
        $provider = $this->telephony->provider();
        $giveUpBefore = now()->subHours((int) config('telephony.reconcile.recording_give_up_hours', 24));
        $resolved = 0;

        $pending = CallRecording::query()
            ->where('status', CallRecordingStatus::Pending->value)
            ->where('created_at', '<', now()->subMinutes(2))
            ->with('call')
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        foreach ($pending as $recording) {
            if ($recording->created_at->lt($giveUpBefore)) {
                $this->recordings->markFailed($recording, 'recording never became available');

                continue;
            }

            if (filled($recording->provider_reference_encrypted)) {
                ProcessCallRecording::dispatch($recording->id);
                $recording->touch();

                continue;
            }

            $call = $recording->call;
            if (! $call?->provider_call_id) {
                continue;
            }

            try {
                $event = $provider->getCall($call->provider_call_id);
            } catch (TelephonyException) {
                break;
            }

            if ($event?->recordingReference) {
                DB::transaction(fn () => $this->recordings->register($call, $event->recordingReference));
                $resolved++;
            } else {
                $recording->touch();
            }
        }

        return $resolved;
    }

    private function applyLocked(Call $call, ProviderCallEvent $event): bool
    {
        return DB::transaction(function () use ($call, $event) {
            $locked = Call::query()->whereKey($call->id)->lockForUpdate()->first();
            if (! $locked) {
                return false;
            }
            $locked->reconcile_attempts++;
            $locked->reconciled_at = now();

            return $this->lifecycle->apply($locked, $event);
        });
    }

    private function touch(Call $call): void
    {
        Call::query()->whereKey($call->id)->update(['reconciled_at' => now(), 'reconcile_attempts' => DB::raw('reconcile_attempts + 1')]);
    }

    private function synthetic(Call $call, CallStatus $status, string $code): ProviderCallEvent
    {
        return new ProviderCallEvent(
            type: ProviderCallEvent::DETAILS,
            providerCallId: (string) ($call->provider_call_id ?? $call->client_reference),
            status: $status,
            providerStatus: $code,
            endedAt: CarbonImmutable::now(),
            talkSeconds: 0,
            failureCode: $code,
        );
    }
}
