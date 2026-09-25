<?php

use App\Http\Controllers\Webhooks\TelephonyWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Public telephony callback endpoints. Registered WITHOUT the "web" group: no
| session, no cookies, no CSRF. The provider adapter authenticates each request
| (Exotel: secret callback token in constant time + optional IP allow-list +
| AccountSid match; status callbacks are only accepted for calls the CRM knows).
*/

Route::match(['get', 'post'], '/webhooks/telephony/{provider}/status', [TelephonyWebhookController::class, 'status'])
    ->where('provider', '[a-z]+')
    ->name('webhooks.telephony.status');

Route::match(['get', 'post'], '/webhooks/telephony/{provider}/passthru', [TelephonyWebhookController::class, 'passthru'])
    ->where('provider', '[a-z]+')
    ->name('webhooks.telephony.passthru');
