<?php

namespace App\Support;

/**
 * Removes credentials from text and structured data before anything is logged,
 * audited or stored as an operational error. Identifiers such as leadgen_id,
 * page_id and form_id are not secrets and are kept for tracing.
 */
final class SecretRedactor
{
    public const REDACTED = '[REDACTED]';

    private const KEY_FRAGMENTS = [
        'password', 'token', 'secret', 'authorization', 'cookie', 'credential',
        'api_key', 'apikey', 'private_key', 'appsecret', 'signature', 'presigned',
    ];

    private const EXACT_KEYS = ['code', 'oauth_code', 'auth_code', 'client_secret', 'hub_challenge'];

    private const QUERY_PARAMS = 'access_token|appsecret_proof|client_secret|fb_exchange_token|input_token|code|hub\.verify_token|hub_verify_token|verify_token|state|token|x-amz-signature|x-amz-credential|x-amz-security-token|signature';

    public static function text(string $value): string
    {
        $value = preg_replace('/(?<=[?&\s"\'])('.self::QUERY_PARAMS.')=([^&\s"\']+)/i', '$1='.self::REDACTED, $value) ?? $value;
        $value = preg_replace('/\b(Bearer|OAuth|Basic)\s+[A-Za-z0-9._\-|+\/=]{8,}/i', '$1 '.self::REDACTED, $value) ?? $value;
        // Credentials embedded in URLs: https://key:token@host/...
        $value = preg_replace('#(\b[a-z][a-z0-9+.\-]*://)[^/\s:@"\']+:[^/\s@"\']+@#i', '$1'.self::REDACTED.'@', $value) ?? $value;
        // Meta access tokens start with "EAA"; app tokens look like "{app_id}|{secret}".
        $value = preg_replace('/\bEAA[A-Za-z0-9]{16,}\b/', self::REDACTED, $value) ?? $value;
        $value = preg_replace('/\b\d{6,20}\|[A-Za-z0-9_\-]{16,}\b/', self::REDACTED, $value) ?? $value;
        $value = preg_replace('/sha256=[a-f0-9]{16,}/i', 'sha256='.self::REDACTED, $value) ?? $value;

        return $value;
    }

    public static function array(array $values, int $depth = 0): array
    {
        $clean = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $clean[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $clean[$key] = $depth > 6 ? self::REDACTED : self::array($value, $depth + 1);
            } elseif (is_string($value)) {
                $clean[$key] = self::text($value);
            } else {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    public static function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);

        if (in_array($key, self::EXACT_KEYS, true)) {
            return true;
        }

        foreach (self::KEY_FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
