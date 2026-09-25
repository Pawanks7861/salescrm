<?php

use App\Services\Reports\ReportRegistry;
use App\Services\Reports\ReportScope;
use Carbon\CarbonImmutable;

/*
| Release-blocking checks A–D: every report, total, chart, table, filter
| option and drill-down is limited to what the viewer may see.
|
| World (Sept 2026, CRM timezone Asia/Kolkata):
|   Rahul   — 2 leads (1 won ₹1,000), 1 connected call (300 s), 1 future follow-up
|   Priya   — 4 "PriyaLead" leads (3 won ₹50,000 each, 1 lost), 2 calls (600 s each),
|             2 overdue follow-ups, 1 upcoming meeting
|   Outside — 3 "MumbaiLead" leads (2 won), 1 call
|   Manager — no leads of their own; legacy team membership grants nothing
*/
beforeEach(function () {
    $this->org = $org = salesOrg();
    $this->travelTo(CarbonImmutable::parse('2026-09-15 11:00', 'Asia/Kolkata'));
    telephonySetup([$org->rahul, $org->priya, $org->outsider]);

    $r1 = reportLead($org->rahul, ['first_name' => 'Ravi', 'last_name' => 'Client', 'estimated_value' => 1000]);
    $r2 = reportLead($org->rahul, ['first_name' => 'Rekha', 'last_name' => 'Client', 'estimated_value' => 2000]);
    moveLead($r1, 'won', $org->rahul);

    $p = collect(range(1, 4))->map(fn ($i) => reportLead($org->priya, ['first_name' => 'PriyaLead', 'last_name' => "N{$i}", 'estimated_value' => 50000, 'city' => 'Surat', 'state' => 'PriyaState']));
    moveLead($p[0], 'won', $org->priya);
    moveLead($p[1], 'won', $org->priya);
    moveLead($p[2], 'won', $org->priya);
    moveLead($p[3], 'lost', $org->priya);

    $m = collect(range(1, 3))->map(fn ($i) => reportLead($org->outsider, ['first_name' => 'MumbaiLead', 'last_name' => "N{$i}", 'estimated_value' => 70000, 'city' => 'Mumbai']));
    moveLead($m[0], 'won', $org->outsider);
    moveLead($m[1], 'won', $org->outsider);

    finishCall($this, startCall($r2, $org->rahul), 'completed', 300);
    finishCall($this, startCall($p[0], $org->priya), 'completed', 600);
    finishCall($this, startCall($p[1], $org->priya), 'completed', 600);
    finishCall($this, startCall($m[2], $org->outsider), 'completed', 900);

    scheduleFollowup($r2, $org->rahul, ['scheduled_date' => '2026-09-16', 'scheduled_time' => '11:00']);
    $pOpen = reportLead($org->priya, ['first_name' => 'PriyaLead', 'last_name' => 'Open']);
    scheduleFollowup($pOpen, $org->priya, ['scheduled_date' => '2026-09-15', 'scheduled_time' => '12:00']);
    scheduleFollowup($pOpen, $org->priya, ['scheduled_date' => '2026-09-15', 'scheduled_time' => '12:30', 'title' => 'Second']);
    scheduleMeeting($pOpen, $org->priya, ['scheduled_date' => '2026-09-17']);

    $this->travelTo(CarbonImmutable::parse('2026-09-15 15:00', 'Asia/Kolkata'));
    $this->month = ['preset' => 'this_month'];
});

function assertNoForeignNames(array $props, array $names): void
{
    $json = json_encode($props);
    foreach ($names as $name) {
        expect($json)->not->toContain($name);
    }
}

test('A: Rahul sees only his own numbers on every report', function () {
    config(['crm.features.lead_value' => true]);
    $o = $this->org;
    $rahul = $o->rahul;

    $overview = reportProps($this, $rahul, 'overview', $this->month);
    expect(reportKpi($overview, 'sales', 'new_leads'))->toBe(2)
        ->and(reportKpi($overview, 'sales', 'won'))->toBe(1)
        ->and((float) reportKpi($overview, 'sales', 'won_value'))->toBe(1000.0)
        ->and(reportKpi($overview, 'sales', 'lost'))->toBe(0)
        ->and(reportKpi($overview, 'activity', 'calls'))->toBe(1)
        ->and(reportKpi($overview, 'activity', 'followups_overdue'))->toBe(0)
        ->and(reportKpi($overview, 'activity', 'meetings_upcoming'))->toBe(0)
        ->and($overview['context']['tier'])->toBe(ReportScope::OWN);

    $calls = reportProps($this, $rahul, 'calls', $this->month);
    expect(reportKpi($calls, 'summary', 'total'))->toBe(1)
        ->and(reportKpi($calls, 'summary', 'talk'))->toBe(300);

    $perf = reportProps($this, $rahul, 'sales-performance', $this->month);
    expect(reportRows($perf, 'performance')->pluck('name')->all())->toBe(['Rahul Sharma'])
        ->and(reportSection($perf, 'performance')['totals']['won'])->toBe(1)
        ->and(reportSection($perf, 'performance')['totals']['calls'])->toBe(1);

    $fu = reportProps($this, $rahul, 'follow-ups', $this->month);
    expect(reportKpi($fu, 'summary', 'overdue_now'))->toBe(0);

    $mtg = reportProps($this, $rahul, 'meetings', $this->month);
    expect(reportKpi($mtg, 'summary', 'upcoming_now'))->toBe(0)
        ->and(reportKpi($mtg, 'summary', 'scheduled'))->toBe(0);

    $pipeline = reportProps($this, $rahul, 'pipeline', $this->month);
    expect(reportKpi($pipeline, 'summary', 'open'))->toBe(1)
        ->and((float) reportKpi($pipeline, 'summary', 'value'))->toBe(2000.0);

    $campaigns = reportProps($this, $rahul, 'campaigns', $this->month);
    expect(reportSection($campaigns, 'sources')['totals']['leads'])->toBe(2);

    $lost = reportProps($this, $rahul, 'lost-leads', $this->month);
    expect(reportKpi($lost, 'summary', 'lost'))->toBe(0);

    // No Priya / other-team value is reachable from ANY report Rahul can open.
    $slugs = collect(app(ReportRegistry::class)->available(ReportScope::for($rahul)))->flatten(1)->pluck('slug')->filter();
    expect($slugs)->not->toContain('teams');
    foreach ($slugs as $slug) {
        assertNoForeignNames(reportProps($this, $rahul, $slug, $this->month), ['Priya', 'PriyaLead', 'PriyaState', 'Surat', 'MumbaiLead', 'Mumbai', 'Outside Exec', '50000', '70000']);
    }
});

test('A: Rahul cannot widen scope by tampering with filters', function () {
    $o = $this->org;

    foreach ([['user' => $o->priya->id], ['team' => $o->otherTeam->id], ['user' => $o->outsider->id]] as $tamper) {
        $props = reportProps($this, $o->rahul, 'overview', [...$this->month, ...$tamper]);
        expect(reportKpi($props, 'sales', 'new_leads'))->toBe(2)
            ->and($props['filters'])->not->toHaveKey('user')
            ->and($props['filters'])->not->toHaveKey('team');
    }
});

test('B: a manager on a legacy team sees only their own report data', function () {
    $o = $this->org;

    $overview = reportProps($this, $o->manager, 'overview', $this->month);
    expect(reportKpi($overview, 'sales', 'new_leads'))->toBe(0)
        ->and(reportKpi($overview, 'activity', 'calls'))->toBe(0)
        ->and($overview['context']['tier'])->toBe(ReportScope::OWN);

    $perf = reportProps($this, $o->manager, 'sales-performance', $this->month);
    expect(reportRows($perf, 'performance')->pluck('name')->all())->toBe(['Mehul Manager']);

    foreach (['overview', 'sales-performance', 'calls', 'pipeline', 'campaigns', 'ageing', 'activity'] as $slug) {
        assertNoForeignNames(reportProps($this, $o->manager, $slug, $this->month), ['Rahul', 'Priya', 'MumbaiLead', 'Outside Exec', 'Ahmedabad Team', 'Mumbai Team', '50000', '70000']);
    }

    $tampered = reportProps($this, $o->manager, 'overview', [...$this->month, 'user' => $o->priya->id, 'team' => $o->team->id]);
    expect(reportKpi($tampered, 'sales', 'new_leads'))->toBe(0)
        ->and($tampered['filters'])->not->toHaveKey('user')
        ->and($tampered['filters'])->not->toHaveKey('team');

    $this->actingAs($o->manager)->get('/reports/teams')->assertNotFound();
});

test('C: filter dropdowns reveal only authorized people and places, never teams', function () {
    $o = $this->org;

    $rahul = reportProps($this, $o->rahul, 'overview', $this->month)['options'];
    expect($rahul['users'])->toBe([])
        ->and($rahul)->not->toHaveKey('teams')
        ->and(collect($rahul['cities'])->all())->toBe(['Ahmedabad']);

    $manager = reportProps($this, $o->manager, 'overview', $this->month)['options'];
    expect($manager['users'])->toBe([])->and($manager)->not->toHaveKey('teams');

    $admin = reportProps($this, $o->admin, 'overview', $this->month)['options'];
    expect(collect($admin['users'])->pluck('label')->implode('|'))->toContain('Outside Exec')->toContain('Priya Patel')
        ->and($admin)->not->toHaveKey('teams')
        ->and(collect($admin['cities'])->all())->toContain('Mumbai');

    $priya = reportProps($this, $o->admin, 'overview', [...$this->month, 'user' => $o->priya->id]);
    expect(reportKpi($priya, 'sales', 'new_leads'))->toBe(5)->and(reportKpi($priya, 'sales', 'won'))->toBe(3);
});

test('D: drill-down links go to operational screens that apply their own visibility', function () {
    $o = $this->org;
    $overview = reportProps($this, $o->rahul, 'overview', $this->month);
    $link = collect(reportSection($overview, 'sales')['items'])->firstWhere('key', 'new_leads')['link'];
    expect($link)->toStartWith(url('/leads'));

    $this->actingAs($o->rahul)->get($link)->assertOk()->assertDontSee('PriyaLead')->assertSee('Ravi');

    // Hand-crafted drill-down for Priya's leads still shows nothing of hers.
    $this->actingAs($o->rahul)->get('/leads?assignee='.$o->priya->id)->assertOk()->assertDontSee('PriyaLead');
    $this->actingAs($o->rahul)->get('/calls?agent='.$o->priya->id)->assertOk()->assertDontSee('PriyaLead');
});

test('admin and super admin see company data', function () {
    $o = $this->org;

    foreach ([$o->admin, $o->super] as $user) {
        $overview = reportProps($this, $user, 'overview', $this->month);
        expect(reportKpi($overview, 'sales', 'new_leads'))->toBe(10)
            ->and(reportKpi($overview, 'sales', 'won'))->toBe(6)
            ->and(reportKpi($overview, 'activity', 'calls'))->toBe(4)
            ->and($overview['context']['tier'])->toBe(ReportScope::ALL);
    }
});

test('a legacy report.view_team grant widens nothing', function () {
    $o = $this->org;
    $manager = legacyRoleGrant($o->manager, 'report.view_team');

    expect(ReportScope::for($manager)->tier)->toBe(ReportScope::OWN)
        ->and(reportKpi(reportProps($this, $manager, 'overview', $this->month), 'sales', 'new_leads'))->toBe(0);

    $viewAll = setPermission($manager, 'report.view_all');
    expect(ReportScope::for($viewAll)->tier)->toBe(ReportScope::ALL);
});

test('unknown reports 404, the removed team report 404s for everyone, no permission 403', function () {
    $o = $this->org;

    $this->actingAs($o->admin)->get('/reports/does-not-exist')->assertNotFound();
    foreach ([$o->rahul, $o->admin, $o->super] as $user) {
        $this->actingAs($user)->get('/reports/teams')->assertNotFound();
    }

    $none = setPermission($o->rahul, 'report.view', 'deny');
    $this->actingAs($none)->get('/reports')->assertForbidden();
    $this->actingAs($none)->get('/reports/overview')->assertForbidden();
    $this->actingAs($none)->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page->where('reportKpis', null));
});

test('dashboard KPIs use the same scope as reports', function () {
    $o = $this->org;

    $kpis = fn ($user) => collect($this->actingAs($user)->get('/dashboard')->viewData('page')['props']['reportKpis']['kpis'])->pluck('value', 'key');

    expect($kpis($o->rahul)['new_leads'])->toBe(2)
        ->and($kpis($o->manager)['new_leads'])->toBe(0)
        ->and($kpis($o->admin)['new_leads'])->toBe(10);
});
