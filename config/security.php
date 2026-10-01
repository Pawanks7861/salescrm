<?php

/*
| Content-Security-Policy for HTML responses (SecurityHeaders middleware).
|
| Scripts need 'self' plus a per-request nonce (Ziggy @routes, Vite prefetch).
| Inline styles stay allowed (Inertia progress bar, Vue style bindings).
| No directive uses a bare "*". Allowed third-party origins:
|   fonts.bunny.net         Inter web font (style-src, font-src)
|   *.fbcdn.net, *.fbsbx.com Facebook Page pictures (Admin → Facebook)
|   www.facebook.com        Meta OAuth redirect after the Connect form (form-action)
|   Firebase (fcm_hosts)    FCM token registration (connect-src)
|
| CSP_REPORT_ONLY=true sends Content-Security-Policy-Report-Only instead, for
| checking a new third-party host on staging before enforcing it.
*/

$list = fn (?string $value) => array_values(array_filter(array_map('trim', explode(',', (string) $value))));

return [

    'csp' => [
        'enabled' => (bool) env('CSP_ENABLED', true),
        'report_only' => (bool) env('CSP_REPORT_ONLY', false),

        // Browser calls these when registering an FCM token. The private key stays on the server.
        'fcm_hosts' => [
            'https://firebaseinstallations.googleapis.com',
            'https://fcmregistrations.googleapis.com',
            'https://firebase.googleapis.com',
        ],

        'extra_connect_src' => $list(env('CSP_EXTRA_CONNECT_SRC')),
        'extra_script_src' => $list(env('CSP_EXTRA_SCRIPT_SRC')),
    ],

];
