<?php

/*
| The CRM is a same-origin Inertia app with no public API, so no path sends
| CORS headers and browsers block every cross-origin read. Meta and Exotel
| webhooks are server-to-server and do not use CORS. If an API is added
| later, list its paths and exact origins here; never "*" for anything that
| uses the session.
*/

return [

    'paths' => [],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'X-Requested-With', 'X-CSRF-TOKEN', 'X-XSRF-TOKEN'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
