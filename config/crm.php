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
    | Platform attribution ("Powered by Buildify360"). Fixed by the platform
    | provider: intentionally not an env value or a CRM setting, so client
    | admins cannot change or remove it. Client branding (company name, logo,
    | favicon) stays in Settings → General → Branding.
    */
    'platform' => [
        'name' => 'Buildify360',
        'url' => 'https://buildify360.com',
    ],

];
