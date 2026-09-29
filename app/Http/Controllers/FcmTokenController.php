<?php

namespace App\Http\Controllers;

use App\Models\FcmToken;
use App\Services\Notifications\FcmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A user registers or removes only their own device token. The response
 * never echoes the token.
 */
class FcmTokenController extends Controller
{
    public function __construct(private readonly FcmService $fcm) {}

    public function store(Request $request): JsonResponse
    {
        abort_unless($this->fcm->isConfigured() && $this->fcm->globallyEnabled(), 409, 'Notifications are not available.');

        $data = $request->validate([
            'token' => ['required', 'string', 'min:20', 'max:4096', 'regex:/^[A-Za-z0-9:_\-.+\/=]+$/'],
        ]);

        $row = $this->fcm->register($request->user(), $data['token'], $request->userAgent());
        $request->session()->put('fcm_token_hash', $row->token_hash);

        return response()->json(['registered' => true], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'min:20', 'max:4096'],
        ]);

        $removed = $this->fcm->unregister($request->user(), $data['token']);
        if ($request->session()->get('fcm_token_hash') === FcmToken::hashToken($data['token'])) {
            $request->session()->forget('fcm_token_hash');
        }

        return response()->json(['removed' => $removed]);
    }
}
