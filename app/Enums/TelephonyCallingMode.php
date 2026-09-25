<?php

namespace App\Enums;

enum TelephonyCallingMode: string
{
    case WebRtc = 'webrtc';
    case Pstn = 'pstn';

    public function label(): string
    {
        return match ($this) {
            self::WebRtc => 'Browser (WebRTC)',
            self::Pstn => 'Phone (click-to-call)',
        };
    }

    public function channel(): CallChannel
    {
        return $this === self::WebRtc ? CallChannel::WebRtc : CallChannel::Pstn;
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
