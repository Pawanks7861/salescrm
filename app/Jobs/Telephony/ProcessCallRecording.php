<?php

namespace App\Jobs\Telephony;

use App\Enums\CallRecordingStatus;
use App\Models\CallRecording;
use App\Services\Telephony\CallRecordingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;
use Throwable;

/** Makes a recording available (and archives it when configured). Retries with backoff. */
class ProcessCallRecording implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public int $timeout = 120;

    public function __construct(public int $recordingId)
    {
        $this->onQueue(config('telephony.queue', 'integrations'));
    }

    /** @return array<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(CallRecordingService $recordings): void
    {
        $recording = CallRecording::query()->find($this->recordingId);
        if (! $recording) {
            return;
        }

        $recordings->process($recording);

        if ($recording->fresh()?->status === CallRecordingStatus::Pending) {
            throw new RuntimeException('Recording not yet available.');
        }
    }

    public function failed(Throwable $e): void
    {
        $recording = CallRecording::query()->find($this->recordingId);
        if ($recording) {
            app(CallRecordingService::class)->markFailed($recording, 'processing gave up after retries');
        }
    }
}
