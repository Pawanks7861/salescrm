<?php

return [

    /*
    | VAPID keys for standard Web Push. Generate with `php artisan webpush:vapid`
    | and put them in .env. The private key must never be committed, logged or
    | sent to the browser; only the public key is shared with the frontend.
    | Browser push requires HTTPS in production (localhost is exempt).
    */
    'vapid' => [
        'public_key' => env('WEBPUSH_VAPID_PUBLIC_KEY'),
        'private_key' => env('WEBPUSH_VAPID_PRIVATE_KEY'),
        'subject' => env('WEBPUSH_VAPID_SUBJECT', env('APP_URL', 'mailto:admin@example.com')),
    ],

    // Seconds the push service keeps an undelivered message (reminders go stale fast).
    'ttl' => (int) env('WEBPUSH_TTL', 3600),

];
