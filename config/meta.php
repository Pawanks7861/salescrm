<?php

/*
| Meta (Facebook / Instagram) Lead Ads integration.
|
| Application-level secrets live ONLY in the environment. They are never
| stored in the database, never sent to the browser and never logged.
| Access tokens obtained through OAuth are stored encrypted in the database.
*/

return [

    'app_id' => env('META_APP_ID'),

    'app_secret' => env('META_APP_SECRET'),

    'webhook_verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),

    // Checked against Meta's version table (Graph API + Marketing API) on 2026-09-24:
    // v25.0 is supported by both until at least 2028. Override with META_GRAPH_VERSION.
    'graph_version' => env('META_GRAPH_VERSION', 'v25.0'),

    'graph_url' => env('META_GRAPH_URL', 'https://graph.facebook.com'),

    'dialog_url' => env('META_DIALOG_URL', 'https://www.facebook.com'),

    // Absolute OAuth redirect URI registered in the Meta app. Defaults to the
    // CRM callback route; never taken from request input.
    'oauth_redirect_uri' => env('META_OAUTH_REDIRECT_URI'),

    'oauth_scopes' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'META_OAUTH_SCOPES',
        'pages_show_list,pages_read_engagement,pages_manage_metadata,pages_manage_ads,leads_retrieval,ads_management,business_management'
    ))))),

    'http' => [
        'connect_timeout' => (int) env('META_HTTP_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('META_HTTP_TIMEOUT', 15),
    ],

    'queue' => env('META_QUEUE', 'integrations'),

    // Processing attempts per lead event before it is marked failed.
    'max_attempts' => (int) env('META_MAX_ATTEMPTS', 5),

    // Seconds between attempts (last value repeats).
    'backoff' => [60, 300, 900, 3600],

    // Webhook body limit (Meta batches at most 1000 updates).
    'max_payload_bytes' => 2 * 1024 * 1024,

    // Per-answer / per-lead limits applied to untrusted form answers.
    'max_answer_length' => 1000,
    'max_fields' => 100,

    // Invalid-signature requests allowed per IP per minute before 429.
    'invalid_signature_limit' => 20,

    // Super Admin "advanced" system-user token entry. Off unless explicitly enabled.
    'allow_manual_token' => (bool) env('META_ALLOW_MANUAL_TOKEN', false),

    // Maximum look-back for "Sync recent leads" (Meta keeps leads for 90 days).
    'backfill_max_days' => 90,

    // How far back meta:poll-leads looks. 1 matches a fetch of the last day.
    'poll_days' => max(1, (int) env('META_POLL_DAYS', 1)),
];
