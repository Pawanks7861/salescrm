<?php

use App\Models\AuditLog;
use App\Models\LeadSource;
use App\Models\User;
use App\Services\Reports\ReportRegistry;
use Carbon\CarbonImmutable;
use Database\Seeders\ReportingDemoSeeder;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/*
| Release checks O (bounded query counts, no N+1) and P (no PII in logs,
| audit or cache), plus REPORT_VIEWED throttling.
*/
beforeEach(function () {
    $this->org = salesOrg();
    $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00', 'Asia/Kolkata'));
});

function reportQueryCount($test, $user, string $slug): int
{
    Cache::flush();
    DB::flushQueryLog();
    DB::enableQueryLog();
    reportProps($test, $user, $slug, ['preset' => 'this_month', 'compare' => 1]);
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

function seedReportLeads(object $org, int $count): void
{
    $users = [$org->rahul, $org->priya, $org->outsider];
    foreach (range(1, $count) as $i) {
        $lead = reportLead($users[$i % 3], ['estimated_value' => 1000 * $i]);
        if ($i % 4 === 0) {
            moveLead($lead, 'won', $users[$i % 3]);
        } elseif ($i % 7 === 0) {
            moveLead($lead, 'lost', $users[$i % 3]);
        }
    }
}

test('O: query counts do not grow with the number of leads (§100)', function () {
    $slugs = collect(ReportRegistry::REPORTS)->keys();

    seedReportLeads($this->org, 25);
    $small = $slugs->mapWithKeys(fn ($s) => [$s => reportQueryCount($this, $this->org->admin, $s)]);

    seedReportLeads($this->org, 75); // 100 total
    $large = $slugs->mapWithKeys(fn ($s) => [$s => reportQueryCount($this, $this->org->admin, $s)]);

    foreach ($slugs as $slug) {
        expect($large[$slug])->toBeLessThanOrEqual($small[$slug] + 2, "{$slug}: {$small[$slug]} → {$large[$slug]} queries");
        expect($large[$slug])->toBeLessThan(120, "{$slug} runs {$large[$slug]} queries");
    }
});

test('O: 1000 leads still render the overview with the same query count', function () {
    seedReportLeads($this->org, 30);
    $small = reportQueryCount($this, $this->org->rahul, 'overview');

    $rows = [];
    $status = leadStatusId('new');
    $source = LeadSource::value('id');
    foreach (range(1, 1000) as $i) {
        $rows[] = ['first_name' => 'Bulk', 'full_name' => 'Bulk '.$i, 'phone' => '7'.str_pad((string) $i, 9, '0', STR_PAD_LEFT),
            'lead_number' => 'BULK-'.$i, 'status_id' => $status, 'source_id' => $source, 'priority' => 'medium',
            'assigned_to' => $this->org->rahul->id, 'created_at' => now(), 'updated_at' => now()];
    }
    foreach (array_chunk($rows, 250) as $chunk) {
        DB::table('leads')->insert($chunk);
    }

    $rahulLeads = DB::table('leads')->where('assigned_to', $this->org->rahul->id)->whereNull('deleted_at')->count();
    expect($rahulLeads)->toBeGreaterThan(1000)
        ->and(reportQueryCount($this, $this->org->rahul, 'overview'))->toBeLessThanOrEqual($small + 2)
        ->and(reportKpi(reportProps($this, $this->org->rahul, 'overview', ['preset' => 'this_month']), 'sales', 'new_leads'))->toBe($rahulLeads);
});

test('REPORT_VIEWED is recorded once per visit, not per filter change', function () {
    $rahul = $this->org->rahul;

    reportProps($this, $rahul, 'overview');
    reportProps($this, $rahul, 'overview', ['preset' => 'today']);
    reportProps($this, $rahul, 'overview', ['preset' => 'last_month']);
    reportProps($this, $rahul, 'pipeline');

    $views = AuditLog::where('action', 'REPORT_VIEWED')->where('user_id', $rahul->id)->get();
    expect($views)->toHaveCount(2)
        ->and($views->pluck('new_values_json.report')->all())->toEqualCanonicalizing(['overview', 'pipeline']);

    // A later visit is a new entry.
    $this->travel(11)->minutes();
    reportProps($this, $rahul, 'overview');
    expect(AuditLog::where('action', 'REPORT_VIEWED')->where('user_id', $rahul->id)->count())->toBe(3);
});

test('P: audit entries, logs and cache hold no report data or customer PII', function () {
    Log::spy();
    reportLead($this->org->rahul, ['first_name' => 'Secretname', 'phone' => '9876501234', 'email' => 'secret.person@example.com', 'estimated_value' => 424242]);
    $manager = setPermission($this->org->manager, 'report.export');
    Storage::fake('local');

    foreach (['overview', 'ageing', 'pipeline', 'sales-performance'] as $slug) {
        reportProps($this, $manager, $slug, ['preset' => 'this_month']);
    }
    $this->actingAs($manager)->post('/reports/ageing/export', ['section' => 'neglected', 'filters' => ['preset' => 'this_month']]);

    $audit = AuditLog::whereIn('action', ['REPORT_VIEWED', 'REPORT_EXPORTED'])->get()->toJson();
    expect($audit)->not->toContain('Secretname')->not->toContain('9876501234')->not->toContain('secret.person')->not->toContain('424242');

    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('debug');

    // The only cache entries written are the per-user view throttles (no results).
    $store = Cache::getStore();
    if ($store instanceof ArrayStore) {
        $keys = array_keys((fn () => $this->storage)->call($store));
        foreach ($keys as $key) {
            expect($key)->not->toContain('Secretname');
            if (str_contains($key, 'report')) {
                expect($key)->toMatch('/^report-viewed:\d+:[a-z\-]+$/');
            }
        }
    }
});

test('reporting demo data is never seeded outside the local environment', function () {
    reportLead($this->org->rahul);
    $this->seed(ReportingDemoSeeder::class);

    expect(DB::table('calls')->count())->toBe(0)
        ->and(User::where('email', 'arjun@salescrm.local')->exists())->toBeFalse();
});

test('responses carry aggregates, not full lead records', function () {
    reportLead($this->org->rahul, ['first_name' => 'Hidden', 'email' => 'hidden.lead@example.com', 'phone' => '9811112222']);

    foreach (['overview', 'leads', 'pipeline', 'funnel', 'campaigns', 'response-time', 'sales-performance'] as $slug) {
        $json = json_encode(reportProps($this, $this->org->manager, $slug, ['preset' => 'this_month'])['sections']);
        expect($json)->not->toContain('hidden.lead@example.com')->not->toContain('9811112222');
    }
});
