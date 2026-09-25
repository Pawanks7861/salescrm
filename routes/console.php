<?php

use App\Console\Commands\ProductionCheck;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Requires the server cron: * * * * * php artisan schedule:run
// Heartbeat read by app:production-check to confirm the cron is running.
Schedule::call(fn () => Cache::forever(ProductionCheck::SCHEDULER_HEARTBEAT_KEY, now()->toIso8601String()))
    ->everyMinute()->name('scheduler-heartbeat')->withoutOverlapping(2);

Schedule::command('followups:dispatch-reminders')->everyMinute()->withoutOverlapping(5);
Schedule::command('meetings:dispatch-reminders')->everyMinute()->withoutOverlapping(5);

// Meta Lead Ads: recover lost/stuck jobs, daily token + subscription check, ledger retention.
Schedule::command('meta:retry-failed')->everyTenMinutes()->withoutOverlapping(10);
Schedule::command('meta:check')->dailyAt('03:15')->withoutOverlapping(30);
Schedule::command('meta:prune-events')->dailyAt('03:45')->withoutOverlapping(30);

// Telephony: recover missed final callbacks / pending recordings; retention.
Schedule::command('telephony:reconcile-pending')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('telephony:prune')->dailyAt('04:15')->withoutOverlapping(30);

// Reports: temporary export files expire (report.export_retention_hours).
Schedule::command('reports:prune-exports')->hourly()->withoutOverlapping(30);
