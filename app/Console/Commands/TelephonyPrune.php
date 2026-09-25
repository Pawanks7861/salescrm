<?php

namespace App\Console\Commands;

use App\Enums\CallEventStatus;
use App\Models\CallEvent;
use App\Services\SettingService;
use App\Services\Telephony\CallRecordingService;
use Illuminate\Console\Command;

/**
 * Retention: expires recordings past their retention date (the call record and
 * its metadata are kept) and removes old processed callback ledger rows.
 * Failed callback events are kept for review.
 */
class TelephonyPrune extends Command
{
    protected $signature = 'telephony:prune';

    protected $description = 'Apply recording and callback-event retention settings';

    public function handle(CallRecordingService $recordings, SettingService $settings): int
    {
        $expired = $recordings->pruneExpired();

        $days = max(7, (int) $settings->get('telephony.event_retention_days', 90));
        $deleted = CallEvent::query()
            ->whereIn('processing_status', [CallEventStatus::Processed->value, CallEventStatus::Ignored->value])
            ->where('received_at', '<', now()->subDays($days))
            ->limit(5000)
            ->delete();

        $this->info("Expired {$expired} recording(s); pruned {$deleted} callback event(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
