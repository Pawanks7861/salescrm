<?php

namespace App\Services\Meta;

use App\Enums\MetaErrorCategory;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

/**
 * Facebook Login (authorization code flow) for the admin "Connect Meta" button.
 *
 * - `state` is 48 random chars bound to the admin's session, single use and
 *   valid for 10 minutes; comparison is constant-time.
 * - The redirect URI is fixed (config or the named callback route) and never
 *   taken from request input, so there are no open redirects.
 * - The short-lived user token is exchanged for a long-lived one; page tokens
 *   derived from it (MetaPageService) do not expire.
 */
class MetaOAuthService
{
    private const SESSION_KEY = 'meta_oauth_state';

    private const STATE_TTL_SECONDS = 600;

    public function __construct(private readonly MetaGraphClient $graph) {}

    public function redirectUri(): string
    {
        return (string) (config('meta.oauth_redirect_uri') ?: route('admin.integrations.facebook.callback'));
    }

    public function authorizationUrl(Session $session, bool $rerequest = false): string
    {
        if (! $this->graph->isConfigured()) {
            throw MetaApiException::of(MetaErrorCategory::Configuration, 'META_APP_ID / META_APP_SECRET are not configured.');
        }

        $state = Str::random(48);
        $session->put(self::SESSION_KEY, ['hash' => hash('sha256', $state), 'expires' => now()->addSeconds(self::STATE_TTL_SECONDS)->getTimestamp()]);

        $query = array_filter([
            'client_id' => config('meta.app_id'),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
            'response_type' => 'code',
            'scope' => implode(',', config('meta.oauth_scopes', [])),
            'auth_type' => $rerequest ? 'rerequest' : null,
        ]);

        return rtrim((string) config('meta.dialog_url'), '/').'/'.$this->graph->version().'/dialog/oauth?'.http_build_query($query);
    }

    /** Validates and consumes the state (always removed, even on failure). */
    public function consumeState(Session $session, mixed $state): bool
    {
        $stored = $session->pull(self::SESSION_KEY);

        if (! is_string($state) || $state === '' || strlen($state) > 128 || ! is_array($stored)) {
            return false;
        }

        if (($stored['expires'] ?? 0) < now()->getTimestamp()) {
            return false;
        }

        return hash_equals((string) ($stored['hash'] ?? ''), hash('sha256', $state));
    }

    /**
     * Exchanges the authorization code for a long-lived user token and reads
     * the account id/name and granted scopes.
     *
     * @return array{access_token: string, token_type: string, expires_at: ?\DateTimeInterface, data_access_expires_at: ?\DateTimeInterface, user_id: ?string, user_name: ?string, scopes: array<int, string>}
     *
     * @throws MetaApiException
     */
    public function exchangeCode(string $code): array
    {
        $short = $this->graph->get('oauth/access_token', [
            'client_id' => config('meta.app_id'),
            'client_secret' => config('meta.app_secret'),
            'redirect_uri' => $this->redirectUri(),
            'code' => $code,
        ]);

        if (empty($short['access_token']) || ! is_string($short['access_token'])) {
            throw MetaApiException::of(MetaErrorCategory::Malformed, 'Meta did not return an access token.');
        }

        $long = $this->graph->get('oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('meta.app_id'),
            'client_secret' => config('meta.app_secret'),
            'fb_exchange_token' => $short['access_token'],
        ]);

        $token = is_string($long['access_token'] ?? null) ? $long['access_token'] : $short['access_token'];

        return $this->describe($token, 'user', isset($long['expires_in']) ? now()->addSeconds((int) $long['expires_in']) : null);
    }

    /**
     * Reads identity + scopes for a token (OAuth result or Super Admin system-user token).
     *
     * @throws MetaApiException
     */
    public function describe(string $token, string $type, ?\DateTimeInterface $expiresAt = null): array
    {
        $debug = $this->graph->get('debug_token', ['input_token' => $token], $this->graph->appAccessToken(), proof: false)['data'] ?? [];

        if (! ($debug['is_valid'] ?? false)) {
            throw MetaApiException::of(MetaErrorCategory::Authentication, 'Meta reports this token as invalid.');
        }
        if ((string) ($debug['app_id'] ?? '') !== (string) config('meta.app_id')) {
            throw MetaApiException::of(MetaErrorCategory::Configuration, 'The token belongs to a different Meta app.');
        }

        $me = $this->graph->get('me', ['fields' => 'id,name'], $token);

        return [
            'access_token' => $token,
            'token_type' => $type,
            'expires_at' => $expiresAt ?? (! empty($debug['expires_at']) ? now()->setTimestamp((int) $debug['expires_at']) : null),
            'data_access_expires_at' => ! empty($debug['data_access_expires_at']) ? now()->setTimestamp((int) $debug['data_access_expires_at']) : null,
            'user_id' => isset($me['id']) ? (string) $me['id'] : null,
            'user_name' => isset($me['name']) ? mb_substr((string) $me['name'], 0, 191) : null,
            'scopes' => array_values(array_map('strval', $debug['scopes'] ?? [])),
        ];
    }
}
