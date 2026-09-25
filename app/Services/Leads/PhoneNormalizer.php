<?php

namespace App\Services\Leads;

use App\Services\SettingService;

/**
 * Normalises phone numbers to a digits-only international form without "+"
 * (e.g. "+91 98765-43210", "098765 43210" and "9876543210" → "919876543210")
 * so duplicate detection and search can use exact indexed matches.
 */
class PhoneNormalizer
{
    /** National significant number length per country calling code. */
    private const NATIONAL_LENGTHS = [
        '1' => 10, '7' => 10, '44' => 10, '49' => 11, '61' => 9, '65' => 8, '91' => 10,
        '92' => 10, '94' => 9, '880' => 10, '971' => 9, '966' => 9, '974' => 8, '977' => 10,
    ];

    private const MIN_DIGITS = 6;

    public function __construct(private readonly SettingService $settings) {}

    public function normalize(?string $phone, ?string $countryCode = null): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $hasPlus = str_starts_with(ltrim($phone), '+');
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
            $hasPlus = true;
        }

        if (strlen($digits) < self::MIN_DIGITS) {
            return null;
        }

        if ($hasPlus) {
            return $digits;
        }

        $country = preg_replace('/\D+/', '', (string) ($countryCode ?? $this->settings->get('lead.default_country_code', '91')));
        $national = self::NATIONAL_LENGTHS[$country] ?? null;

        if ($national === null || $country === '') {
            return ltrim($digits, '0') ?: null;
        }

        if (strlen($digits) === $national + 1 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) === $national) {
            return $country.$digits;
        }

        return $digits;
    }
}
