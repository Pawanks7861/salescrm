<?php

use App\Models\Lead;
use App\Services\SettingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/*
| Release checks H (Meta enquiries vs unique leads) and N (CRM timezone).
*/

test('H: two Meta submissions from one person = 2 enquiries, 1 unique lead (§94)', function () {
    Http::preventStrayRequests();
    $org = salesOrg();
    metaSetup();
    app(SettingService::class)->updateGroup('lead', ['lead.duplicate_handling' => 'merge']);
    $this->travelTo(CarbonImmutable::parse('2026-09-24 12:00', 'Asia/Kolkata'));

    metaFakeGraph(['8001' => metaLead('8001'), '8002' => metaLead('8002')]);
    metaPost($this, metaPayload(metaChange('8001')))->assertOk();
    metaPost($this, metaPayload(metaChange('8002')))->assertOk();

    expect(Lead::count())->toBe(1);

    $props = reportProps($this, $org->admin, 'campaigns', ['preset' => 'today']);
    expect(reportKpi($props, 'meta', 'enquiries'))->toBe(2)
        ->and(reportKpi($props, 'meta', 'unique'))->toBe(1)
        ->and(reportKpi($props, 'meta', 'repeat'))->toBe(1)
        ->and(reportSection($props, 'sources')['totals']['leads'])->toBe(1);

    $form = reportRows($props, 'meta_forms')->firstWhere('name', 'Home Loan Enquiry');
    expect($form)->toMatchArray(['enquiries' => 2, 'leads' => 1, 'repeat' => 1]);

    // Enquiry counts follow lead visibility too.
    $lead = Lead::sole();
    if ($lead->assigned_to !== $org->outsider->id) {
        $lead->forceFill(['assigned_to' => $org->outsider->id, 'team_id' => $org->otherTeam->id])->save();
    }
    expect(reportKpi(reportProps($this, $org->rahul, 'campaigns', ['preset' => 'today']), 'meta', 'enquiries'))->toBe(0);
});

test('N: day boundaries follow the CRM timezone, not UTC (§99)', function () {
    $org = salesOrg();

    // 23:59 IST on the 14th = 18:29 UTC on the 14th; 00:00 IST on the 15th = 18:30 UTC on the 14th.
    $this->travelTo(CarbonImmutable::parse('2026-09-14 23:59', 'Asia/Kolkata'));
    reportLead($org->rahul, ['first_name' => 'LateNight']);
    $this->travelTo(CarbonImmutable::parse('2026-09-15 00:00', 'Asia/Kolkata'));
    reportLead($org->rahul, ['first_name' => 'Midnight']);
    $this->travelTo(CarbonImmutable::parse('2026-09-15 05:00', 'Asia/Kolkata'));
    reportLead($org->rahul, ['first_name' => 'Morning']);

    $today = reportProps($this, $org->rahul, 'overview', ['preset' => 'today']);
    expect(reportKpi($today, 'sales', 'new_leads'))->toBe(2);

    $yesterday = reportProps($this, $org->rahul, 'overview', ['preset' => 'yesterday']);
    expect(reportKpi($yesterday, 'sales', 'new_leads'))->toBe(1);

    // Daily grouping puts the 23:59 lead on the 14th and both others on the 15th.
    $trend = reportSection(reportProps($this, $org->rahul, 'leads', ['preset' => 'custom', 'from' => '2026-09-14', 'to' => '2026-09-15']), 'trend');
    expect($trend['labels'])->toBe(['Sep 14', 'Sep 15'])
        ->and($trend['datasets'][0]['data'])->toBe([1, 2]);

    // This month starts at 00:00 IST on the 1st.
    $this->travelTo(CarbonImmutable::parse('2026-08-31 23:30', 'Asia/Kolkata'));
    reportLead($org->rahul, ['first_name' => 'August']);
    $this->travelTo(CarbonImmutable::parse('2026-09-15 06:00', 'Asia/Kolkata'));
    expect(reportKpi(reportProps($this, $org->rahul, 'overview', ['preset' => 'this_month']), 'sales', 'new_leads'))->toBe(3);
});

test('N: a call crossing midnight belongs to the local day it started', function () {
    $org = salesOrg();
    telephonySetup([$org->rahul]);
    $this->travelTo(CarbonImmutable::parse('2026-09-14 23:58', 'Asia/Kolkata'));
    $lead = reportLead($org->rahul);
    $call = startCall($lead, $org->rahul);

    $this->travelTo(CarbonImmutable::parse('2026-09-15 00:05', 'Asia/Kolkata'));
    finishCall($this, $call, 'completed', 400);

    $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00', 'Asia/Kolkata'));
    expect(reportKpi(reportProps($this, $org->rahul, 'calls', ['preset' => 'yesterday']), 'summary', 'total'))->toBe(1)
        ->and(reportKpi(reportProps($this, $org->rahul, 'calls', ['preset' => 'today']), 'summary', 'total'))->toBe(0);
});

test('invalid custom ranges fall back to the default preset', function () {
    $org = salesOrg();
    $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00', 'Asia/Kolkata'));

    foreach ([['from' => '2026-09-10', 'to' => '2026-09-01'], ['from' => 'x', 'to' => 'y'], ['from' => '2020-01-01', 'to' => '2026-09-01']] as $range) {
        $filters = reportProps($this, $org->rahul, 'overview', ['preset' => 'custom', ...$range])['filters'];
        expect($filters['preset'])->toBe('last_30_days')->and($filters['to'])->toBe('2026-09-15');
    }
});
