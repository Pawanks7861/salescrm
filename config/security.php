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
|   Exotel                  CRM Web SDK script origin (from EXOTEL_WEBRTC_SDK_URL)
|                           plus exotel_hosts for its API/WebSocket/media traffic
|
| The exact hosts the Exotel SDK contacts depend on the SDK version enabled on
| the client's account: verify browser calling on staging with the console
| open, and add any blocked host to CSP_EXTRA_CONNECT_SRC. CSP_REPORT_ONLY=true
| sends Content-Security-Policy-Report-Only instead (for that staging check).
*/

$list = fn (?string $value) => array_values(array_filter(array_map('trim', explode(',', (string) $value))));

return [

    'csp' => [
        'enabled' => (bool) env('CSP_ENABLED', true),
        'report_only' => (bool) env('CSP_REPORT_ONLY', false),

        'exotel_hosts' => [
            'https://*.exotel.com', 'wss://*.exotel.com',
            'https://*.exotel.in', 'wss://*.exotel.in',
        ],

        'extra_connect_src' => $list(env('CSP_EXTRA_CONNECT_SRC')),
        'extra_script_src' => $list(env('CSP_EXTRA_SCRIPT_SRC')),
    ],

];
