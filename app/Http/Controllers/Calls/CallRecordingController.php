<?php

namespace App\Http\Controllers\Calls;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Services\AuditService;
use App\Services\Telephony\CallRecordingService;
use App\Services\Telephony\Data\RecordingStream;
use App\Services\Telephony\TelephonyException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Recording playback / download, always proxied through the CRM: the
 * provider URL never reaches the browser. Listening requires
 * call.recording.listen, downloading call.recording.download, and both
 * require CallVisibility (and therefore lead visibility).
 */
class CallRecordingController extends Controller
{
    public function __construct(
        private readonly CallRecordingService $recordings,
        private readonly AuditService $audit,
    ) {}

    public function stream(Request $request, Call $call): StreamedResponse
    {
        $this->authorize('listen', $call);
        $recording = $call->recording;
        abort_unless($recording?->isPlayable(), 404);

        $range = $request->header('Range');
        $audio = $this->fetch($recording, $range);

        // One audit entry per listening session — seeking issues many Range requests.
        if (Cache::add("call-recording-listened:{$request->user()->id}:{$call->id}", true, now()->addMinutes(10))) {
            $this->audit->log(AuditAction::CallRecordingListened, 'calls', $call, "Recording of call {$call->call_number} played", null, ['call_number' => $call->call_number]);
        }

        return $this->respond($audio, 'inline', $call);
    }

    public function download(Request $request, Call $call): StreamedResponse
    {
        $this->authorize('download', $call);
        $recording = $call->recording;
        abort_unless($recording?->isPlayable(), 404);

        $audio = $this->fetch($recording, null);
        $this->audit->log(AuditAction::CallRecordingDownloaded, 'calls', $call, "Recording of call {$call->call_number} downloaded", null, ['call_number' => $call->call_number]);

        return $this->respond($audio, 'attachment', $call);
    }

    private function fetch($recording, ?string $range): RecordingStream
    {
        try {
            return $this->recordings->stream($recording, $range);
        } catch (TelephonyException $e) {
            abort($e->category === TelephonyException::UNAVAILABLE ? 503 : 404, $e->getMessage());
        }
    }

    private function respond(RecordingStream $audio, string $disposition, Call $call): StreamedResponse
    {
        $extension = str_contains($audio->mimeType, 'wav') ? 'wav' : 'mp3';
        $headers = [
            'Content-Type' => $audio->mimeType,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => $disposition.'; filename="'.$call->call_number.'.'.$extension.'"',
        ];
        if ($audio->length !== null) {
            $headers['Content-Length'] = (string) $audio->length;
        }
        if ($audio->contentRange !== null) {
            $headers['Content-Range'] = $audio->contentRange;
        }

        $body = $audio->body;

        return response()->stream(function () use ($body) {
            while (! $body->eof()) {
                echo $body->read(65536);
                flush();
            }
        }, $audio->status, $headers);
    }
}
