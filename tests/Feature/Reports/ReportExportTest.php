<?php

use App\Jobs\GenerateReportExport;
use App\Models\AuditLog;
use App\Models\ReportExport;
use App\Services\Reports\ReportExportService;
use App\Services\SettingService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
| Release-blocking checks E–G: executives cannot export (403 from the backend),
| managers need an explicit grant, exports obey visibility, files are private,
| owner-only and temporary, and audit entries never contain the data.
*/
beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->org = $org = salesOrg();
    $this->travelTo(CarbonImmutable::parse('2026-09-15 11:00', 'Asia/Kolkata'));

    reportLead($org->rahul, ['first_name' => 'Ravi', 'last_name' => 'Client']);
    moveLead(reportLead($org->priya, ['first_name' => 'PriyaLead', 'last_name' => 'One', 'estimated_value' => 50000]), 'won', $org->priya);
    reportLead($org->outsider, ['first_name' => 'MumbaiLead', 'last_name' => 'One']);

    $this->export = fn ($user, $report = 'sales-performance', $section = 'performance', $filters = ['preset' => 'this_month']) => $this->actingAs($user)
        ->from('/reports/'.$report)
        ->post("/reports/{$report}/export", ['section' => $section, 'filters' => $filters]);
});

test('E: sales executive gets 403 and the attempt is audited', function () {
    ($this->export)($this->org->rahul)->assertForbidden();
    ($this->export)($this->org->rahul, 'overview', 'sources')->assertForbidden();

    expect(ReportExport::count())->toBe(0);
    $audit = AuditLog::where('action', 'EXPORT_ATTEMPTED')->where('user_id', $this->org->rahul->id)->firstOrFail();
    expect($audit->new_values_json)->toMatchArray(['report' => 'sales-performance', 'section' => 'performance']);

    // The UI never offers the button either.
    expect(reportProps($this, $this->org->rahul, 'overview')['context']['can_export'])->toBeFalse();
});

test('managers do not get export permission automatically', function () {
    ($this->export)($this->org->manager)->assertForbidden();
    expect(reportProps($this, $this->org->manager, 'overview')['context']['can_export'])->toBeFalse();
});

test('F: a granted manager exports only their own row; audit has no data', function () {
    $manager = setPermission($this->org->manager, 'report.export');

    ($this->export)($manager)->assertRedirect('/reports/sales-performance')->assertSessionHas('download');

    $export = ReportExport::sole();
    expect($export->status)->toBe(ReportExport::READY)
        ->and($export->user_id)->toBe($manager->id)
        ->and($export->path)->toStartWith('report-exports/');

    $csv = Storage::disk('local')->get($export->path);
    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->toContain('Mehul Manager')
        ->not->toContain('Rahul Sharma')->not->toContain('Priya Patel')
        ->not->toContain('Outside Exec')->not->toContain('Team');

    $audit = AuditLog::where('action', 'REPORT_EXPORTED')->sole();
    expect(array_keys($audit->new_values_json))->toEqualCanonicalizing(['report', 'section', 'format', 'filters', 'row_count'])
        ->and($audit->new_values_json['row_count'])->toBe($export->row_count)
        ->and(json_encode($audit->new_values_json))->not->toContain('Priya')->not->toContain('Rahul');
});

test('F: admin export covers the company per salesperson, never per team', function () {
    $admin = setPermission($this->org->admin, 'report.export');
    ($this->export)($admin)->assertSessionHas('download');

    $csv = Storage::disk('local')->get(ReportExport::sole()->path);
    expect($csv)->toContain('Rahul Sharma')->toContain('Priya Patel')->toContain('Outside Exec')
        ->not->toContain('Ahmedabad Team')->not->toContain('Mumbai Team');

    ($this->export)($admin, 'teams', 'performance')->assertNotFound();
});

test('G: files are private, owner-only and expire', function () {
    $manager = setPermission($this->org->manager, 'report.export');
    $admin = setPermission($this->org->admin, 'report.export');
    ($this->export)($manager);
    $export = ReportExport::sole();

    // Never on the public disk, never serialized with its path.
    expect(Storage::disk('public')->allFiles())->toBe([])
        ->and($export->toArray())->not->toHaveKey('path')->not->toHaveKey('disk');

    // The private disk has no public file route at all (filesystems.disks.local.serve = false).
    $this->actingAs($manager)->get('/storage/'.$export->path)->assertNotFound();

    $url = route('reports.exports.download', $export);
    expect($url)->toContain($export->uuid)->not->toContain('report-exports/'.$export->uuid.'.csv');

    $response = $this->actingAs($manager)->get($url)->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $this->assertDatabaseHas('audit_logs', ['action' => 'REPORT_EXPORT_DOWNLOADED', 'user_id' => $manager->id]);

    // Another privileged user cannot use the link.
    $this->actingAs($admin)->get($url)->assertForbidden();
    // Guests are redirected to login.
    auth()->logout();
    $this->get($url)->assertRedirect('/login');

    // Losing the permission blocks downloads of existing files.
    $revoked = setPermission($manager, 'report.export', 'deny');
    $this->actingAs($revoked)->get($url)->assertForbidden();
    $manager = setPermission($revoked, 'report.export', 'grant');

    // Expired after the retention window, then pruned from disk.
    $this->travel(25)->hours();
    $this->actingAs($manager)->get($url)->assertNotFound();
    expect(app(ReportExportService::class)->prune())->toBe(1);
    expect(Storage::disk('local')->exists($export->path))->toBeFalse()
        ->and($export->fresh()->status)->toBe(ReportExport::EXPIRED);
});

test('large exports are queued and generated by the job', function () {
    Queue::fake();
    app(SettingService::class)->updateGroup('report', ['report.export_queue_threshold' => 100]);
    $admin = setPermission($this->org->admin, 'report.export');
    foreach (range(1, 101) as $i) {
        reportLead($this->org->rahul);
    }
    $this->travel(6)->hours(); // untouched > 4 h → neglected

    ($this->export)($admin, 'ageing', 'neglected')->assertRedirect()->assertSessionMissing('download');

    $export = ReportExport::sole();
    expect($export->status)->toBe(ReportExport::QUEUED)->and($export->row_count)->toBeGreaterThan(100);
    Queue::assertPushed(GenerateReportExport::class, fn ($job) => $job->exportId === $export->id);

    (new GenerateReportExport($export->id))->handle(app(ReportExportService::class));
    $export->refresh();
    expect($export->status)->toBe(ReportExport::READY)->and($export->row_count)->toBe(103);

    $csv = Storage::disk('local')->get($export->path);
    expect(substr_count(trim($csv), "\n"))->toBe(103); // header + 103 rows
});

test('export re-checks permission when the queued job runs', function () {
    Queue::fake();
    app(SettingService::class)->updateGroup('report', ['report.export_queue_threshold' => 100]);
    $admin = setPermission($this->org->admin, 'report.export');
    foreach (range(1, 101) as $i) {
        reportLead($this->org->rahul);
    }
    $this->travel(6)->hours();
    ($this->export)($admin, 'ageing', 'neglected');
    setPermission($admin, 'report.export', 'deny');

    $export = ReportExport::sole();
    expect(fn () => (new GenerateReportExport($export->id))->handle(app(ReportExportService::class)))->toThrow(AuthorizationException::class);
    expect($export->fresh()->status)->toBe(ReportExport::FAILED)->and($export->fresh()->path)->toBeNull();
});

test('unknown sections and reports are rejected', function () {
    $admin = setPermission($this->org->admin, 'report.export');

    ($this->export)($admin, 'overview', 'nope')->assertNotFound();
    ($this->export)($admin, 'nope', 'sources')->assertNotFound();
    ($this->export)($admin, 'overview', '../etc')->assertSessionHasErrors('section');
});

test('CSV cells are protected against formula injection', function () {
    $admin = setPermission($this->org->admin, 'report.export');
    $this->org->rahul->forceFill(['name' => '=HYPERLINK("x")'])->save();

    ($this->export)($admin);
    expect(Storage::disk('local')->get(ReportExport::sole()->path))->toContain("'=HYPERLINK");
});
