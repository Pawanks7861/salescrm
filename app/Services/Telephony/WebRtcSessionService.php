<?php

namespace App\Services\Telephony;

use App\Models\User;
use App\Services\SettingService;
use App\Support\Permissions;

/**
 * Softphone boot data and browser-calling sessions. A session (provider
 * credentials for the SDK) is issued only when ALL hold: the user is active,
 * holds call.make, has an enabled calling account, and the integration is
 * active with browser calling enabled. Each agent gets their own provider
 * identity — there is no shared SIP account. Nothing here is logged.
 */
class WebRtcSessionService
{
    public function __construct(
        private readonly TelephonyManager $telephony,
        private readonly TelephonyDirectory $directory,
        private readonly CallService $calls,
        private readonly SettingService $settings,
    ) {}

    /** Non-secret configuration for the softphone widget. */
    public function config(User $user): array
    {
        $availability = $this->calls->availability($user);
        $integration = $this->telephony->activeIntegration();
        $recording = (bool) $integration?->recording_enabled;

        return [
            'enabled' => $availability['enabled'],
            'reason' => $availability['reason'],
            'modes' => $availability['modes'],
            'default_mode' => $availability['default'],
            'driver' => $availability['enabled'] ? $this->telephony->provider()->name() : null,
            'can_receive' => $user->hasPermission(Permissions::CALL_RECEIVE) && $integration !== null,
            'can_manual_dial' => $availability['enabled'] && $user->hasPermission(Permissions::CALL_MANUAL_DIAL),
            'recording' => $recording,
            'recording_notice' => $recording && $this->settings->get('telephony.recording_notice_enabled', true)
                ? (string) $this->settings->get('telephony.recording_notice_text')
                : null,
        ];
    }

    /** @throws TelephonyException */
    public function create(User $user): array
    {
        if (! $user->is_active || ! $user->hasPermission(Permissions::CALL_MAKE)) {
            throw TelephonyException::notConfigured('You are not allowed to use browser calling.');
        }

        $integration = $this->telephony->activeIntegration();
        if (! $integration || ! $integration->browser_calling_enabled) {
            throw TelephonyException::notConfigured('Browser calling is not enabled.');
        }

        $agent = $this->directory->agentFor($integration, $user);
        if (! $agent) {
            throw TelephonyException::notConfigured('Your calling account is not set up yet. Please contact your administrator.');
        }

        $session = $this->telephony->provider()->createWebRtcSession($agent);
        $agent->forceFill(['last_registered_at' => now()])->save();

        return $session->toArray();
    }
}
