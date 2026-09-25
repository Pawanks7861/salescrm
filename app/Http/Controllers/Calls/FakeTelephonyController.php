<?php

namespace App\Http\Controllers\Calls;

use App\Enums\CallStatus;
use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Services\Telephony\CallWebhookService;
use App\Services\Telephony\Providers\FakeTelephonyProvider;
use App\Services\Telephony\TelephonyDirectory;
use App\Services\Telephony\TelephonyManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Local development only: drives the fake provider through the SAME callback
 * pipeline a real provider uses. Unreachable unless the fake driver is active
 * AND the environment is local/testing.
 */
class FakeTelephonyController extends Controller
{
    public function __construct(
        private readonly TelephonyManager $telephony,
        private readonly CallWebhookService $webhooks,
    ) {
        abort_unless($this->telephony->isFake() && TelephonyManager::fakeAllowed(), 404);
    }

    public function simulate(Request $request, Call $call): JsonResponse
    {
        $this->authorize('view', $call);
        abort_unless((int) $call->agent_user_id === $request->user()->id, 403);

        $data = $request->validate([
            'status' => ['required', Rule::in(['ringing', 'answered', 'completed', 'busy', 'no_answer', 'failed', 'cancelled', 'missed'])],
            'talk_seconds' => ['nullable', 'integer', 'min:0', 'max:36000'],
        ]);

        $connected = $data['status'] === CallStatus::Completed->value;
        $event = FakeTelephonyProvider::eventFromArray(array_filter([
            'call_id' => $call->provider_call_id ?? 'FAKE-'.Str::upper(Str::random(16)),
            'reference' => $call->client_reference,
            'status' => $data['status'],
            'direction' => $call->direction->value,
            'talk_seconds' => $connected ? ($data['talk_seconds'] ?? max(1, (int) now()->diffInSeconds($call->answered_at ?? $call->started_at))) : null,
            'recording_url' => $connected ? 'fake://recording/'.$call->id : null,
        ], fn ($v) => $v !== null));

        $this->webhooks->ingest($event, $request->ip());

        return response()->json(['ok' => true]);
    }

    /** Simulates an incoming call from `number` routed to the current user. */
    public function incoming(Request $request, TelephonyDirectory $directory): JsonResponse
    {
        $data = $request->validate(['number' => ['required', 'string', 'max:30']]);
        $integration = $this->telephony->integration();
        $agent = $integration ? $directory->agentFor($integration, $request->user()) : null;
        abort_unless($agent, 422, 'Your calling account is not set up.');

        $sid = 'FAKE-IN-'.Str::upper(Str::random(12));
        $event = FakeTelephonyProvider::eventFromArray([
            'call_id' => $sid,
            'status' => 'incoming',
            'from' => $data['number'],
            'to' => '+918000000001',
            'virtual_number' => '+918000000001',
        ]);
        $this->webhooks->ingest($event, $request->ip());

        $call = Call::query()->where('provider', 'fake')->where('provider_call_id', $sid)->first();
        if ($call && (int) $call->agent_user_id !== $request->user()->id) {
            $call->forceFill(['agent_user_id' => $request->user()->id])->save();
        }

        return response()->json(['provider_call_id' => $sid, 'number' => $data['number']]);
    }
}
