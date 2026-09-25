<?php

namespace App\Enums;

/**
 * Classification of integration failures. Only transient categories are
 * retried automatically; the rest need a configuration fix and a manual retry.
 */
enum MetaErrorCategory: string
{
    case Authentication = 'authentication';
    case Permission = 'permission';
    case NotFound = 'not_found';
    case PageUnavailable = 'page_unavailable';
    case RateLimit = 'rate_limit';
    case Temporary = 'temporary';
    case Network = 'network';
    case Malformed = 'malformed';
    case Mapping = 'mapping';
    case Validation = 'validation';
    case Configuration = 'configuration';

    public function isRetryable(): bool
    {
        return in_array($this, [self::RateLimit, self::Temporary, self::Network], true);
    }

    /** Plain-language explanation shown to administrators. */
    public function message(): string
    {
        return match ($this) {
            self::Authentication => 'Meta authorization expired or was revoked. Reconnect the Meta account.',
            self::Permission => 'A required Meta permission is missing. Reconnect and grant all requested permissions.',
            self::NotFound => 'The lead or object no longer exists at Meta (or is older than Meta keeps leads).',
            self::PageUnavailable => 'Page access is no longer available to the connected account.',
            self::RateLimit => 'Meta rate limit reached. The event will be retried automatically.',
            self::Temporary => 'Meta reported a temporary error. The event will be retried automatically.',
            self::Network => 'Could not reach Meta. The event will be retried automatically.',
            self::Malformed => 'Meta returned an unexpected response.',
            self::Mapping => 'The form mapping could not be applied.',
            self::Validation => 'The lead could not be saved because CRM validation failed.',
            self::Configuration => 'The integration is not configured for this Page or Form.',
        };
    }
}
