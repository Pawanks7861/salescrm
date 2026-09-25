<?php

namespace App\Enums;

/**
 * How the agent's leg is carried. The provider (Exotel, …) is stored separately.
 *   webrtc   — browser softphone
 *   pstn     — provider rings the agent's registered phone (click-to-call)
 *   provider — routed entirely by the provider (e.g. inbound call flow)
 */
enum CallChannel: string
{
    case WebRtc = 'webrtc';
    case Pstn = 'pstn';
    case Provider = 'provider';

    public function label(): string
    {
        return match ($this) {
            self::WebRtc => 'Browser',
            self::Pstn => 'Phone (click-to-call)',
            self::Provider => 'Provider routed',
        };
    }
}
