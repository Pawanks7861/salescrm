<?php

namespace App\Services\Notifications;

/**
 * PHP's OpenSSL cannot create P-256 keys, which Web Push encryption needs for
 * every message. On Windows this usually means OPENSSL_CONF is not set for
 * the web server / queue worker process (see docs/BROWSER_NOTIFICATIONS.md).
 */
class PushEncryptionUnavailable extends \RuntimeException
{
    public static function check(): void
    {
        $key = @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);

        if ($key === false) {
            while (openssl_error_string() !== false) {
                // drain the OpenSSL error queue
            }

            throw new self('OpenSSL cannot create EC keys; set OPENSSL_CONF for this PHP process.');
        }
    }
}
