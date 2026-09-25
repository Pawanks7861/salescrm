<?php

namespace App\Services\Telephony;

use App\Models\TelephonyIntegration;
use App\Services\Telephony\Providers\ExotelTelephonyProvider;
use App\Services\Telephony\Providers\FakeTelephonyProvider;
use App\Services\Telephony\Providers\TelephonyProviderInterface;
use RuntimeException;

/**
 * Resolves the configured provider (TELEPHONY_DRIVER) and its integration row.
 * The fake simulator can never be resolved outside local/testing, so a
 * mis-set production environment fails loudly instead of faking calls.
 */
class TelephonyManager
{
    private ?TelephonyProviderInterface $provider = null;

    public function driver(): string
    {
        return (string) config('telephony.driver', 'exotel');
    }

    public function provider(): TelephonyProviderInterface
    {
        return $this->provider ??= $this->resolve($this->driver());
    }

    public function isFake(): bool
    {
        return $this->driver() === 'fake';
    }

    public static function fakeAllowed(): bool
    {
        return app()->environment(config('telephony.fake_allowed_environments', ['local', 'testing']));
    }

    /** The integration row for the active driver (null until an admin configures it). */
    public function integration(): ?TelephonyIntegration
    {
        return TelephonyIntegration::query()->where('provider', $this->driver())->first();
    }

    public function integrationOrNew(): TelephonyIntegration
    {
        $integration = $this->integration();
        if ($integration) {
            return $integration;
        }

        $integration = new TelephonyIntegration;
        $integration->forceFill([
            'provider' => $this->driver(),
            'name' => $this->isFake() ? 'Local simulator' : ucfirst($this->driver()),
            'is_active' => false,
            'browser_calling_enabled' => false,
            'pstn_calling_enabled' => true,
            'recording_enabled' => true,
            'default_calling_mode' => 'pstn',
        ]);

        return $integration;
    }

    /** Active integration, or null when calling is switched off. */
    public function activeIntegration(): ?TelephonyIntegration
    {
        $integration = $this->integration();

        return $integration?->is_active ? $integration : null;
    }

    private function resolve(string $driver): TelephonyProviderInterface
    {
        return match ($driver) {
            'exotel' => new ExotelTelephonyProvider(config('telephony.exotel', []), config('telephony.http', [])),
            'fake' => self::fakeAllowed()
                ? new FakeTelephonyProvider((string) config('telephony.fake.webhook_secret'))
                : throw new RuntimeException('The fake telephony driver is only available in local/testing environments.'),
            default => throw new RuntimeException("Unsupported telephony driver [{$driver}]."),
        };
    }
}
