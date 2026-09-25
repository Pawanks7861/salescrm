<?php

use App\Http\Controllers\Webhooks\MetaWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Public webhook endpoints. Registered WITHOUT the "web" group: no session,
| no cookies, no CSRF. Each endpoint authenticates the sender itself
| (Meta: verify token on GET, X-Hub-Signature-256 over the raw body on POST).
*/

Route::get('/webhooks/meta/leads', [MetaWebhookController::class, 'verify'])->name('webhooks.meta.verify');
Route::post('/webhooks/meta/leads', [MetaWebhookController::class, 'receive'])->name('webhooks.meta.receive');
