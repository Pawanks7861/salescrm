<?php

use App\Models\Lead;
use App\Models\ReportExport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
| Phase 7.1: lead estimated value is hidden from forms, props, reports and
| exports while crm.features.lead_value is off (the default). Stored values
| are retained and nothing overwrites them.
*/
beforeEach(function () {
    $this->org = salesOrg();
    $this->travelTo(CarbonImmutable::parse('2026-09-15 11:00', 'Asia/Kolkata'));
    $this->lead = reportLead($this->org->rahul, ['first_name' => 'Amit', 'last_name' => 'Desai', 'estimated_value' => 75000]);
});

function pageProps(TestResponse $response): array
{
    $response->assertOk();

    return $response->viewData('page')['props'];
}

test('the feature is off by default', function () {
    expect(config('crm.features.lead_value'))->toBeFalse();
});

test('lead create and edit forms do not offer or send the value', function () {
    $create = pageProps($this->actingAs($this->org->rahul)->get('/leads/create'));
    expect($create['can']['leadValue'])->toBeFalse();

    $edit = pageProps($this->actingAs($this->org->rahul)->get("/leads/{$this->lead->id}/edit"));
    expect($edit['can']['leadValue'])->toBeFalse()
        ->and($edit['lead'])->not->toHaveKey('estimated_value')
        ->and(json_encode($edit))->not->toContain('75000');
});

test('the value is not accepted from the create form', function () {
    $this->actingAs($this->org->rahul)
        ->post('/leads', leadPayload(['first_name' => 'Value', 'estimated_value' => 50000]))
        ->assertRedirect();

    expect(Lead::where('first_name', 'Value')->sole()->estimated_value)->toBeNull();
});

test('updating a lead through the form keeps the stored value untouched', function () {
    $this->actingAs($this->org->rahul)
        ->put("/leads/{$this->lead->id}", leadPayload(['first_name' => 'Amit', 'last_name' => 'Desai', 'phone' => $this->lead->phone, 'estimated_value' => 1]))
        ->assertSessionHasNoErrors();

    expect((float) $this->lead->fresh()->estimated_value)->toBe(75000.0);
});

test('Lead 360, the lead list and the pipeline do not expose the value', function () {
    $show = pageProps($this->actingAs($this->org->rahul)->get("/leads/{$this->lead->id}"));
    expect($show['lead'])->not->toHaveKey('estimated_value')->not->toHaveKey('probability');

    $index = pageProps($this->actingAs($this->org->rahul)->get('/leads'));
    expect($index['leads']['data'])->not->toBeEmpty()
        ->and($index['leads']['data'][0])->not->toHaveKey('estimated_value');

    $pipeline = $this->actingAs($this->org->rahul)->get('/leads/pipeline');
    expect(json_encode(pageProps($pipeline)))->not->toContain('estimated_value')->not->toContain('75000');
});

test('dashboard and reports contain no money KPIs, value charts or value columns', function () {
    moveLead(reportLead($this->org->rahul, ['estimated_value' => 99999]), 'won', $this->org->rahul);

    $dashboard = pageProps($this->actingAs($this->org->rahul)->get('/dashboard'));
    $dashboardKpis = collect($dashboard['reportKpis']['kpis']);
    expect($dashboardKpis->pluck('key')->all())->toContain('won')->not->toContain('won_value')->not->toContain('pipeline')
        ->and($dashboardKpis->pluck('format')->all())->not->toContain('currency');

    foreach (['overview', 'pipeline', 'leads', 'sales-performance', 'lost-leads', 'campaigns'] as $slug) {
        $props = reportProps($this, $this->org->admin, $slug, ['preset' => 'this_month']);
        $json = json_encode($props['sections']);
        expect($json)->not->toContain('"format":"currency"', "{$slug} still shows a currency metric")
            ->not->toContain('"key":"no_value"')
            ->not->toContain('"key":"weighted"')
            ->not->toContain('75000')
            ->not->toContain('99999');
    }

    $pipeline = reportProps($this, $this->org->admin, 'pipeline', ['preset' => 'this_month']);
    expect(reportKpi($pipeline, 'summary', 'open'))->toBe(1);
});

test('report CSV exports omit value columns', function () {
    Storage::fake('local');
    $admin = setPermission($this->org->admin, 'report.export');
    moveLead(reportLead($this->org->priya, ['estimated_value' => 50000]), 'won', $this->org->priya);

    $this->actingAs($admin)->from('/reports/sales-performance')
        ->post('/reports/sales-performance/export', ['section' => 'performance', 'filters' => ['preset' => 'this_month']])
        ->assertSessionHas('download');

    $csv = Storage::disk('local')->get(ReportExport::sole()->path);
    expect($csv)->toContain('Rahul Sharma')
        ->not->toContain('Won value')
        ->not->toContain('50000');
});

test('re-enabling the flag restores the value everywhere (data was retained)', function () {
    config(['crm.features.lead_value' => true]);

    $show = pageProps($this->actingAs($this->org->rahul)->get("/leads/{$this->lead->id}"));
    expect((float) $show['lead']['estimated_value'])->toBe(75000.0);

    $pipeline = reportProps($this, $this->org->rahul, 'pipeline', ['preset' => 'this_month']);
    expect((float) reportKpi($pipeline, 'summary', 'value'))->toBe(75000.0);
});
