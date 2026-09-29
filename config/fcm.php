<?php

/*
| Firebase Cloud Messaging (HTTP v1). Optional. When the service account
| fields are set, lead-assigned and follow-up reminder notifications are
| also sent to each user's registered device tokens. The private key never
| leaves the server. The web block is the public Firebase web config and
| is the only part shared with the browser.
|
| Paste the service-account private key as one line with \n between lines.
*/

return [

    'project_id' => env('FCM_PROJECT_ID'),

    'client_email' => env('FCM_CLIENT_EMAIL'),

    'private_key' => env('FCM_PRIVATE_KEY'),

    'web' => [
        'api_key' => env('FCM_WEB_API_KEY'),
        'auth_domain' => env('FCM_WEB_AUTH_DOMAIN'),
        'project_id' => env('FCM_PROJECT_ID'),
        'messaging_sender_id' => env('FCM_MESSAGING_SENDER_ID'),
        'app_id' => env('FCM_WEB_APP_ID'),
        'vapid_key' => env('FCM_WEB_VAPID_KEY'),
    ],

];
