<?php

namespace App\Services\Telephony;

use App\Enums\AuditAction;
use App\Enums\CallRecordingStatus;
use App\Enums\CallRecordingStorage;
use App\Jobs\Telephony\ProcessCallRecording;
use App\Models\Call;
use App\Models\CallRecording;
use App\Services\AuditService;
use App\Services\SettingService;
use App\Services\Telephony\Data\RecordingStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Recording references and audio. The provider URL is stored encrypted and is
 * never sent to the browser; playback is proxied (with HTTP Range support)
 * through the authorised CRM route. With "private_storage" the audio is
 * archived to the private disk by a queued job.
 *
 *   pending   → reference not yet known / being processed
 *   available → playable
 *   failed    → never became available (call itself stays valid)
 *   expired   → removed by retention (call metadata is kept)
 *   deleted   → removed by an administrator (audited)
 */
class CallRecordingService
{
    public function __construct(
        private readonly TelephonyManager $telephony,
        private readonly SettingService $settings,
        private readonly AuditService $audit,
    ) {}

    /**
     * Creates (once per call) the recording row for a connected call and
     * attaches the provider reference when known. Idempotent.
     */
    public function register(Call $call, ?string $reference): void
    {
        if (! ($call->integration?->recording_enabled ?? true)) {
            return;
        }

        $provider = $this->telephony->provider();
        $reference = $reference !== null && $provider->acceptsRecordingReference($reference) ? $reference : null;

        $recording = CallRecording::query()->where('call_id', $call->id)->lockForUpdate()->first();

        if (! $recording) {
            $recording = new CallRecording;
            $recording->forceFill([
                'call_id' => $call->id,
                'storage_type' => CallRecordingStorage::Provider,
                'status' => CallRecordingStatus::Pending,
                'provider_reference_encrypted' => $reference,
            ])->save();
        } elseif ($reference !== null && $recording->status === CallRecordingStatus::Pending && blank($recording->provider_reference_encrypted)) {
            $recording->forceFill(['provider_reference_encrypted' => $reference])->save();
        } else {
            return;
        }

        if ($reference !== null) {
            $id = $recording->id;
            DB::afterCommit(fn () => ProcessCallRecording::dispatch($id));
        }
    }

    /** Makes a pending recording with a known reference available (archiving it when configured). */
    public function process(CallRecording $recording): void
    {
        if ($recording->status !== CallRecordingStatus::Pending || blank($recording->provider_reference_encrypted)) {
            return;
        }

        $recording->increment('attempts');
        $archive = $this->settings->get('telephony.recording_storage', 'provider') === CallRecordingStorage::PrivateStorage->value;
        $updates = [];

        if ($archive) {
            $stored = $this->archive($recording);
            if ($stored === null) {
                return;
            }
            $updates = $stored;
        }

        $retention = (int) $this->settings->get('telephony.recording_retention_days', 180);

        DB::transaction(function () use ($recording, $updates, $retention) {
            $fresh = CallRecording::query()->whereKey($recording->id)->lockForUpdate()->first();
            if (! $fresh || $fresh->status !== CallRecordingStatus::Pending) {
                return;
            }

            $fresh->forceFill($updates + [
                'status' => CallRecordingStatus::Available,
                'available_at' => now(),
                'expires_at' => $retention > 0 ? now()->addDays($retention) : null,
                'duration_seconds' => $fresh->duration_seconds ?? $fresh->call?->talk_duration_seconds,
            ])->save();

            $call = $fresh->call;
            $this->audit->log(AuditAction::CallRecordingAvailable, 'calls', $call, "Recording available for call {$call->call_number}", null,
                ['call_number' => $call->call_number, 'storage' => $fresh->storage_type->value], null);
        });
    }

    public function markFailed(CallRecording $recording, string $reason): void
    {
        if ($recording->status !== CallRecordingStatus::Pending) {
            return;
        }

        $recording->forceFill(['status' => CallRecordingStatus::Failed, 'failure_reason' => mb_substr($reason, 0, 255)])->save();
        Log::notice('Call recording unavailable', ['call_id' => $recording->call_id, 'reason' => $reason]);
    }

    /**
     * Audio for playback/download. `$range` is the raw Range header.
     *
     * @throws TelephonyException
     */
    public function stream(CallRecording $recording, ?string $range): RecordingStream
    {
        if (! $recording->isPlayable()) {
            throw new TelephonyException(TelephonyException::NOT_FOUND, 'Recording unavailable.');
        }

        if ($recording->storage_type === CallRecordingStorage::PrivateStorage) {
            return $this->streamLocal($recording, $range);
        }

        return $this->telephony->provider()->getRecording((string) $recording->provider_reference_encrypted, $this->sanitizeRange($range));
    }

    /**
     * Retention: expires recordings past `expires_at`, deleting archived audio
     * and the provider reference. Call metadata is never touched. Bounded.
     */
    public function pruneExpired(int $limit = 500): int
    {
        $count = 0;

        CallRecording::query()
            ->where('status', CallRecordingStatus::Available->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->with('call:id,call_number')
            ->limit($limit)
            ->get()
            ->each(function (CallRecording $recording) use (&$count) {
                if ($recording->storage_type === CallRecordingStorage::PrivateStorage && $recording->disk && $recording->path) {
                    Storage::disk($recording->disk)->delete($recording->path);
                }

                $recording->forceFill([
                    'status' => CallRecordingStatus::Expired,
                    'provider_reference_encrypted' => null,
                    'path' => null,
                    'deleted_at' => now(),
                ])->save();

                $this->audit->log(AuditAction::CallRecordingDeleted, 'calls', $recording->call, "Recording for call {$recording->call?->call_number} expired (retention)", null,
                    ['call_number' => $recording->call?->call_number, 'reason' => 'retention'], null);
                $count++;
            });

        return $count;
    }

    /** @return array<string, mixed>|null storage columns, or null when the attempt failed (retried later) */
    private function archive(CallRecording $recording): ?array
    {
        try {
            $audio = $this->telephony->provider()->getRecording((string) $recording->provider_reference_encrypted);
        } catch (TelephonyException $e) {
            Log::warning('Call recording download failed', ['call_id' => $recording->call_id, 'category' => $e->category]);

            return null;
        }

        $disk = (string) config('telephony.recording_disk', 'local');
        $ext = str_contains($audio->mimeType, 'wav') ? 'wav' : 'mp3';
        $call = $recording->call;
        $path = 'call-recordings/'.$call->started_at->format('Y/m').'/'.$call->call_number.'-'.$recording->id.'.'.$ext;
        $max = (int) config('telephony.recording_max_bytes');

        $source = $audio->body;
        $tmp = fopen('php://temp', 'w+b');
        $size = 0;
        while (! $source->eof()) {
            $chunk = $source->read(65536);
            $size += strlen($chunk);
            if ($size > $max) {
                fclose($tmp);
                $this->markFailed($recording, 'recording exceeds size limit');

                return null;
            }
            fwrite($tmp, $chunk);
        }
        rewind($tmp);
        Storage::disk($disk)->put($path, $tmp);
        fclose($tmp);

        return [
            'storage_type' => CallRecordingStorage::PrivateStorage,
            'disk' => $disk,
            'path' => $path,
            'mime_type' => $audio->mimeType,
            'file_size' => $size,
            'archived_at' => now(),
            'provider_reference_encrypted' => null,
        ];
    }

    private function streamLocal(CallRecording $recording, ?string $range): RecordingStream
    {
        $disk = Storage::disk((string) $recording->disk);
        if (! $recording->path || ! $disk->exists($recording->path)) {
            throw new TelephonyException(TelephonyException::NOT_FOUND, 'Recording unavailable.');
        }

        $size = (int) $disk->size($recording->path);
        $handle = $disk->readStream($recording->path);
        $mime = $recording->mime_type ?: 'audio/mpeg';

        $parsed = $this->parseRange($this->sanitizeRange($range), $size);
        if ($parsed === null) {
            return new RecordingStream(Utils::streamFor($handle), 200, $mime, $size);
        }

        [$start, $end] = $parsed;
        fseek($handle, $start);
        $length = $end - $start + 1;
        $chunk = Utils::streamFor(stream_get_contents($handle, $length));
        fclose($handle);

        return new RecordingStream($chunk, 206, $mime, $length, "bytes {$start}-{$end}/{$size}");
    }

    /** Single byte range only ("bytes=a-b", "bytes=a-", "bytes=-n"); anything else is ignored. */
    private function sanitizeRange(?string $range): ?string
    {
        return $range !== null && preg_match('/^bytes=(\d{0,15})-(\d{0,15})$/', trim($range)) && trim($range) !== 'bytes=-' ? trim($range) : null;
    }

    /** @return array{0: int, 1: int}|null */
    private function parseRange(?string $range, int $size): ?array
    {
        if ($range === null || $size === 0 || ! preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m)) {
            return null;
        }

        if ($m[1] === '') {
            $start = max(0, $size - (int) $m[2]);
            $end = $size - 1;
        } else {
            $start = (int) $m[1];
            $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
        }

        return $start <= $end && $start < $size ? [$start, $end] : null;
    }
}
