<?php

use App\Models\ReportExport;
use App\Services\SettingService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;

/*
| Phase 8 §43: temporary export files expire after the configured retention
| and are removed from disk by the hourly prune.
*/

beforeEach(function () {
    Storage::fake('local');
    $this->org = salesOrg();
    $this->travelTo(CarbonImmutable::parse('2026-09-15 11:00', 'Asia/Kolkata'));
    reportLead($this->org->rahul);
    $this->admin = setPermission($this->org->admin, 'report.export');
});

function exportNow($test, $user): ReportExport
{
    $test->actingAs($user)->from('/reports/sales-performance')->post('/reports/sales-performance/export', ['section' => 'performance', 'filters' => ['preset' => 'this_month']]);

    return ReportExport::latest('id')->firstOrFail();
}

test('exports respect the configured retention window', function () {
    app(SettingService::class)->updateGroup('report', ['report.export_retention_hours' => 1]);
    $export = exportNow($this, $this->admin);
    $url = route('reports.exports.download', $export);

    expect($export->expires_at->diffInMinutes(now()))->toBeLessThanOrEqual(60);
    $this->actingAs($this->admin)->get($url)->assertOk();

    $this->travel(61)->minutes();
    $this->actingAs($this->admin)->get($url)->assertNotFound();
});

test('the prune command deletes expired files and marks them expired', function () {
    $export = exportNow($this, $this->admin);
    Storage::disk('local')->assertExists($export->path);

    $this->travel(25)->hours();
    $this->artisan('reports:prune-exports')->assertSuccessful();

    Storage::disk('local')->assertMissing($export->path);
    expect($export->fresh()->status)->toBe(ReportExport::EXPIRED);
});

test('unexpired exports survive the prune', function () {
    $export = exportNow($this, $this->admin);

    $this->artisan('reports:prune-exports')->assertSuccessful();

    Storage::disk('local')->assertExists($export->path);
    expect($export->fresh()->status)->toBe(ReportExport::READY);
});

test('the prune runs hourly without overlapping', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command ?? '', 'reports:prune-exports'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
