<?php

namespace App\Services\Security;

use App\Services\SettingService;

/**
 * Restricts CRM sign-in and signed-in pages to the Medawk office network.
 * Browsers cannot see a WiFi name, so the office is identified by its public
 * IP or CIDR. The restriction stays off until it is enabled and at least one
 * address is saved, so an empty list cannot lock everyone out.
 */
class OfficeNetworkGuard
{
    public const MESSAGE = 'The CRM can only be used on the Medawk office WiFi.';

    public function __construct(private readonly SettingService $settings) {}

    public function enforced(): bool
    {
        if (config('crm.office_wifi_bypass')) {
            return false;
        }

        return (bool) $this->settings->get('security.office_wifi_only', false) && $this->allowedIps() !== [];
    }

    public function allows(?string $ip): bool
    {
        if (! $this->enforced()) {
            return true;
        }

        if ($ip === null || $ip === '') {
            return false;
        }

        foreach ($this->allowedIps() as $rule) {
            if ($this->matches($ip, $rule)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    public function allowedIps(): array
    {
        $raw = (string) $this->settings->get('security.office_wifi_ips', '');
        $parts = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique($parts));
    }

    private function matches(string $ip, string $rule): bool
    {
        if (! str_contains($rule, '/')) {
            return $ip === $rule;
        }

        [$subnet, $bits] = explode('/', $rule, 2);
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || ! filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        $bits = (int) $bits;
        if ($bits < 0 || $bits > 32) {
            return false;
        }
        if ($bits === 0) {
            return true;
        }

        $mask = -1 << (32 - $bits);

        return (ip2long($ip) & $mask) === (ip2long($subnet) & $mask);
    }
}
