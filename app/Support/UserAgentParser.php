<?php

namespace App\Support;

final class UserAgentParser
{
    /** @return array{browser: string, platform: string, device: string} */
    public static function parse(?string $userAgent): array
    {
        $ua = (string) $userAgent;

        return [
            'browser' => self::browser($ua),
            'platform' => self::platform($ua),
            'device' => self::device($ua),
        ];
    }

    private static function browser(string $ua): string
    {
        return match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            str_contains($ua, 'MSIE') || str_contains($ua, 'Trident/') => 'Internet Explorer',
            $ua === '' => 'Unknown',
            default => 'Other',
        };
    }

    private static function platform(string $ua): string
    {
        return match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Linux') => 'Linux',
            $ua === '' => 'Unknown',
            default => 'Other',
        };
    }

    private static function device(string $ua): string
    {
        return match (true) {
            (bool) preg_match('/iPad|Tablet/i', $ua) || (str_contains($ua, 'Android') && ! str_contains($ua, 'Mobile')) => 'tablet',
            (bool) preg_match('/Mobile|iPhone|Android/i', $ua) => 'mobile',
            $ua === '' => 'unknown',
            default => 'desktop',
        };
    }
}
