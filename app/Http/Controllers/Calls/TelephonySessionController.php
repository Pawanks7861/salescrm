<?php

namespace App\Http\Controllers\Calls;

use App\Http\Controllers\Controller;
use App\Services\Telephony\IncomingCallService;
use App\Services\Telephony\TelephonyException;
use App\Services\Telephony\WebRtcSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelephonySessionController extends Controller
{
    /**
     * Softphone configuration + transient browser-calling credentials.
     * Returned only to the authenticated, active, permitted, mapped agent;
     * never cached, never placed in Inertia props, kept in memory client-side.
     */
    public function session(Request $request, WebRtcSessionService $sessions): JsonResponse
    {
        try {
            $payload = $sessions->create($request->user());
        } catch (TelephonyException $e) {
            return response()->json(['message' => $e->getMessage()], 422)->header('Cache-Control', 'no-store');
        }

        return response()->json($payload)->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }

    /** Softphone boot data (no credentials). */
    public function config(Request $request, WebRtcSessionService $sessions): JsonResponse
    {
        return response()->json($sessions->config($request->user()))->header('Cache-Control', 'no-store');
    }

    public function identify(Request $request, IncomingCallService $incoming): JsonResponse
    {
        $data = $request->validate([
            'provider_call_id' => ['nullable', 'string', 'max:100'],
            'number' => ['nullable', 'string', 'max:40'],
        ]);

        return response()->json($incoming->identify($request->user(), $data['provider_call_id'] ?? null, $data['number'] ?? null))->header('Cache-Control', 'no-store');
    }
}
