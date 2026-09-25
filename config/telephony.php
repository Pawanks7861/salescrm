<?php

/*
| Telephony (calling + call recording).
|
| Provider credentials live ONLY in the environment. They are never stored in
| the database, never sent to the browser and never logged. The admin screen
| only shows whether each value is configured.
|
| Drivers:
|   exotel — Exotel Voice v1 (click-to-call / status callbacks / passthru) and
|            the Exotel IP-PSTN CRM Web SDK for browser calling.
|   fake   — local development and automated tests ONLY. It refuses to boot in
|            any other environment (see TelephonyManager).
*/

return [

    'driver' => env('TELEPHONY_DRIVER', 'exotel'),

    // Environments in which the fake driver may be resolved.
    'fake_allowed_environments' => ['local', 'testing'],

    'exotel' => [
        'account_sid' => env('EXOTEL_ACCOUNT_SID'),
        'api_key' => env('EXOTEL_API_KEY'),
        'api_token' => env('EXOTEL_API_TOKEN'),

        // API host for the account's cluster: api.exotel.com (Singapore) or api.in.exotel.com (Mumbai).
        'subdomain' => env('EXOTEL_SUBDOMAIN', 'api.exotel.com'),

        // Shared secret appended to every callback URL the CRM hands to Exotel
        // (Exotel does not sign voice callbacks). Compared in constant time.
        'webhook_secret' => env('EXOTEL_WEBHOOK_SECRET'),

        // ExoPhone used as caller ID when no default number is configured in the CRM.
        'default_caller_id' => env('EXOTEL_DEFAULT_CALLER_ID'),

        // Optional comma-separated list of source IPs/CIDRs allowed to hit the
        // callback endpoints (obtain Exotel's egress IPs from Exotel support).
        'webhook_allowed_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('EXOTEL_WEBHOOK_ALLOWED_IPS', ''))))),

        // Browser calling (IP-PSTN intermix, CRM Web SDK). The access token is
        // generated from the Exotel integrations token API (valid ~90 days) and
        // served to the browser only through the authenticated session endpoint.
        'webrtc_access_token' => env('EXOTEL_WEBRTC_ACCESS_TOKEN'),
        'webrtc_sdk_url' => env('EXOTEL_WEBRTC_SDK_URL'),

        // Timezone of the date-times Exotel sends (the account's timezone).
        'timezone' => env('EXOTEL_TIMEZONE', 'Asia/Kolkata'),

        // Seconds Exotel rings each leg / maximum call length.
        'ring_timeout' => (int) env('EXOTEL_RING_TIMEOUT', 30),
        'time_limit' => (int) env('EXOTEL_TIME_LIMIT', 3600),

        // Hosts recording URLs may be fetched from (SSRF protection). Suffix match.
        'recording_hosts' => ['exotel.com', 'exotel.in', 'amazonaws.com'],
    ],

    // Local/testing simulator. Its callback token is not a real credential.
    'fake' => [
        'webhook_secret' => env('TELEPHONY_FAKE_WEBHOOK_SECRET', 'local-fake-callback-token'),
    ],

    'http' => [
        'connect_timeout' => (int) env('TELEPHONY_HTTP_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('TELEPHONY_HTTP_TIMEOUT', 15),
        'recording_timeout' => (int) env('TELEPHONY_RECORDING_TIMEOUT', 60),
    ],

    'queue' => env('TELEPHONY_QUEUE', 'integrations'),

    // Private disk used when recordings are archived into CRM storage.
    'recording_disk' => env('TELEPHONY_RECORDING_DISK', 'local'),
    'recording_max_bytes' => 100 * 1024 * 1024,

    // Callback body limit.
    'max_payload_bytes' => 256 * 1024,

    // Rejected callbacks allowed per IP per minute before 429.
    'invalid_callback_limit' => 20,

    'reconcile' => [
        // Calls still open this many minutes after creation are checked with the provider.
        'stale_after_minutes' => (int) env('TELEPHONY_RECONCILE_AFTER_MINUTES', 10),
        // Calls open longer than this are closed as failed when the provider has no answer.
        'abandon_after_hours' => 24,
        // Maximum calls checked per run.
        'batch_size' => 100,
        // Recording lookups give up after this many hours.
        'recording_give_up_hours' => 24,
    ],
];
