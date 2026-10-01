<?php

return [

    // Release version; shown to Super Admin / Admin in System settings only.
    'version' => '1.0.0',

    /*
    | Feature switches for CRM capabilities that are built but switched off.
    | lead_value: estimated/lead value in forms, Lead 360, lists, pipeline,
    | dashboard, reports and exports. The leads.estimated_value column and its
    | data are always retained.
    */
    'features' => [
        'lead_value' => (bool) env('CRM_LEAD_VALUE_ENABLED', false),
    ],

    /*
    | Emergency switch if the Medawk WiFi allow-list locks everyone out.
    | Set OFFICE_WIFI_BYPASS=true, then php artisan config:cache.
    */
    'office_wifi_bypass' => (bool) env('OFFICE_WIFI_BYPASS', false),

    /*
    | Platform attribution ("Powered by Buildify360"). Fixed by the platform
    | provider: intentionally not an env value or a CRM setting, so client
    | admins cannot change or remove it. Client branding (company name, logo,
    | favicon) stays in Settings → General → Branding.
    */
    'platform' => [
        'name' => 'Buildify360',
        'url' => 'https://buildify360.com',
    ],

    /*
    | Internal one-to-one chat. Attachments are stored on the private "local"
    | disk and only served through the participant-checked download route.
    */
    'chat' => [
        'max_attachment_kb' => (int) env('CHAT_MAX_ATTACHMENT_KB', 10240),
        'max_attachments' => (int) env('CHAT_MAX_ATTACHMENTS', 5),
        'max_message_length' => 5000,
        'allowed_extensions' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'jpg', 'jpeg', 'png', 'webp', 'zip'],
    ],

];
