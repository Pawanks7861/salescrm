<?php

use App\Models\Followup;
use App\Models\Lead;
use App\Models\LeadStatusChange;
use App\Services\Followups\FollowupService;
use App\Services\Meetings\MeetingCompletionService;
use App\Services\Meetings\MeetingService;
use Carbon\CarbonImmutable;

/*
| Metric accuracy (§93–§98, release checks I–M): each KPI is computed on the
| server from the documented timestamp and denominator.
*/
beforeEach(function () {
    $this->org = salesOrg();
    $this->at = fn (string $local) => $this->travelTo(CarbonImmutable::parse($local, 'Asia/Kolkata'));
});

test('I: first response attempt and first contact are distinct (§93)', function () {
    $o = $this->org;

    ($this->at)('2026-09-15 10:00');
    $lead = reportLead($o->rahul);
    $noAnswer = scheduleFollowup($lead, $o->rahul, ['scheduled_date' => '2026-09-15', 'scheduled_time' => '10:30']);
    $reached = scheduleFollowup($lead, $o->rahul, ['scheduled_date' => '2026-09-15', 'scheduled_time' => '11:00', 'title' => 'Second']);

    ($this->at)('2026-09-15 10:04');
    app(FollowupService::class)->complete($noAnswer->fresh(), ['outcome' => 'no_answer'], $o->rahul);

    ($this->at)('2026-09-15 10:12');
    app(FollowupService::class)->complete($reached->fresh(), ['outcome' => 'connected'], $o->rahul);

    ($this->at)('2026-09-15 18:00');
    $props = reportProps($this, $o->rahul, 'response-time', ['preset' => 'today']);
    expect(reportKpi($props, 'summary', 'leads'))->toBe(1)
        ->and(reportKpi($props, 'summary', 'median_attempt'))->toEqual(240)
        ->and(reportKpi($props, 'summary', 'median_contact'))->toEqual(720)
        ->and(reportKpi($props, 'summary', 'within_target'))->toBe(100.0);
});

test('I: a lead with no outreach counts as "no attempt", not zero minutes', function () {
    ($this->at)('2026-09-15 10:00');
    reportLead($this->org->rahul);

    $props = reportProps($this, $this->org->rahul, 'response-time', ['preset' => 'today']);
    expect(reportKpi($props, 'summary', 'median_attempt'))->toBeNull()
        ->and(reportKpi($props, 'summary', 'no_attempt'))->toBe(1);
});

test('K: overdue follow-ups use the dynamic rule and reschedules are not double counted (§96)', function () {
    $o = $this->org;
    ($this->at)('2026-09-15 09:00');
    $lead = reportLead($o->rahul);

    $a = scheduleFollowup($lead, $o->rahul, ['scheduled_date' => '2026-09-15', 'scheduled_time' => '10:00']);
    $b = scheduleFollowup($lead, $o->rahul, ['scheduled_date' => '2026-09-15', 'scheduled_time' => '11:00', 'title' => 'B']);
    scheduleFollowup($lead, $o->rahul, ['scheduled_date' => '2026-09-16', 'scheduled_time' => '11:00', 'title' => 'C']);

    ($this->at)('2026-09-15 10:30');
    app(FollowupService::class)->complete($a->fresh(), ['outcome' => 'connected'], $o->rahul);
    app(FollowupService::class)->reschedule($b->fresh(), ['scheduled_date' => '2026-09-15', 'scheduled_time' => '12:00'], $o->rahul);

    ($this->at)('2026-09-15 13:00');
    $props = reportProps($this, $o->rahul, 'follow-ups', ['preset' => 'today']);
    // Due so far today: A (completed) + B's replacement at 12:00 (pending, overdue). B itself is history.
    expect(reportKpi($props, 'summary', 'due'))->toBe(2)
        ->and(reportKpi($props, 'summary', 'completed'))->toBe(1)
        ->and(reportKpi($props, 'summary', 'completion_rate'))->toBe(50.0)
        ->and(reportKpi($props, 'summary', 'rescheduled'))->toBe(1)
        ->and(reportKpi($props, 'summary', 'overdue_now'))->toBe(1);

    // Nothing is ever stored as "overdue".
    expect(Followup::where('status', 'overdue')->count())->toBe(0);

    // Overdue moves with the clock.
    ($this->at)('2026-09-16 12:00');
    expect(reportKpi(reportProps($this, $o->rahul, 'follow-ups', ['preset' => 'this_month']), 'summary', 'overdue_now'))->toBe(2);
});

test('L: completed, cancelled, no-show and rescheduled meetings are classified correctly (§97)', function () {
    $o = $this->org;
    ($this->at)('2026-09-10 09:00');
    $lead = reportLead($o->rahul);
    $meetings = app(MeetingService::class);

    $done = scheduleMeeting($lead, $o->rahul, ['scheduled_date' => '2026-09-11', 'start_time' => '10:00', 'end_time' => '11:00']);
    $cancelled = scheduleMeeting($lead, $o->rahul, ['scheduled_date' => '2026-09-12', 'start_time' => '10:00', 'end_time' => '11:00']);
    $noShow = scheduleMeeting($lead, $o->rahul, ['scheduled_date' => '2026-09-13', 'start_time' => '10:00', 'end_time' => '11:00']);
    $moved = scheduleMeeting($lead, $o->rahul, ['scheduled_date' => '2026-09-14', 'start_time' => '10:00', 'end_time' => '11:00']);

    $meetings->cancel($cancelled, 'Client travelling', $o->rahul);
    $meetings->reschedule($moved, ['scheduled_date' => '2026-09-20', 'start_time' => '10:00', 'end_time' => '11:00'], $o->rahul);

    ($this->at)('2026-09-13 12:00');
    app(MeetingCompletionService::class)->complete($done->fresh(), ['outcome' => 'interested', 'notes' => 'Good demo'], $o->rahul);
    $meetings->markNoShow($noShow->fresh(), [], $o->rahul);

    ($this->at)('2026-09-15 12:00');
    $props = reportProps($this, $o->rahul, 'meetings', ['preset' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30']);
    expect(reportKpi($props, 'summary', 'scheduled'))->toBe(4) // done, cancelled, no-show, replacement — never the original
        ->and(reportKpi($props, 'summary', 'completed'))->toBe(1)
        ->and(reportKpi($props, 'summary', 'cancelled'))->toBe(1)
        ->and(reportKpi($props, 'summary', 'no_show'))->toBe(1)
        ->and(reportKpi($props, 'summary', 'rescheduled'))->toBe(1)
        ->and(reportKpi($props, 'summary', 'completion_rate'))->toBe(33.3)
        ->and(reportKpi($props, 'summary', 'no_show_rate'))->toBe(50.0)
        // Only the replacement on the 20th is upcoming; the rescheduled original never counts.
        ->and(reportKpi($props, 'summary', 'upcoming_now'))->toBe(1);
});

test('M: a lead created last month and won this month is last month\'s cohort but this month\'s win (§98)', function () {
    config(['crm.features.lead_value' => true]);
    $o = $this->org;
    ($this->at)('2026-08-20 10:00');
    $lead = reportLead($o->rahul, ['estimated_value' => 25000]);

    ($this->at)('2026-09-05 10:00');
    moveLead($lead, 'won', $o->rahul);

    ($this->at)('2026-09-15 10:00');
    $sept = reportProps($this, $o->rahul, 'overview', ['preset' => 'this_month']);
    expect(reportKpi($sept, 'sales', 'new_leads'))->toBe(0)
        ->and(reportKpi($sept, 'sales', 'won'))->toBe(1)
        ->and((float) reportKpi($sept, 'sales', 'won_value'))->toBe(25000.0)
        ->and(reportKpi($sept, 'sales', 'win_rate'))->toBe(100.0)
        ->and(reportKpi($sept, 'sales', 'cohort_conversion'))->toBeNull();

    $aug = reportProps($this, $o->rahul, 'overview', ['preset' => 'last_month']);
    expect(reportKpi($aug, 'sales', 'new_leads'))->toBe(1)
        ->and(reportKpi($aug, 'sales', 'won'))->toBe(0)
        ->and(reportKpi($aug, 'sales', 'cohort_conversion'))->toBe(100.0);
});

test('status history is recorded for every status change through LeadService', function () {
    ($this->at)('2026-09-15 10:00');
    $lead = reportLead($this->org->rahul);
    moveLead($lead, 'contacted', $this->org->rahul);
    moveLead($lead, 'lost', $this->org->rahul);
    moveLead($lead, 'interested', $this->org->rahul);

    $rows = LeadStatusChange::where('lead_id', $lead->id)->orderBy('id')->get();
    expect($rows)->toHaveCount(4)
        ->and($rows->pluck('to_status_id')->all())->toBe([leadStatusId('new'), leadStatusId('contacted'), leadStatusId('lost'), leadStatusId('interested')])
        ->and($rows->last()->assigned_to)->toBe($this->org->rahul->id);
});

test('a lead reopened after a loss is not counted as lost now; the period loss stays in history', function () {
    ($this->at)('2026-09-10 10:00');
    $lead = reportLead($this->org->rahul);
    moveLead($lead, 'lost', $this->org->rahul);
    moveLead($lead, 'interested', $this->org->rahul);

    ($this->at)('2026-09-15 10:00');
    $props = reportProps($this, $this->org->rahul, 'lost-leads', ['preset' => 'this_month']);
    expect(reportKpi($props, 'summary', 'lost'))->toBe(1);

    $pipeline = reportProps($this, $this->org->rahul, 'pipeline', ['preset' => 'this_month']);
    expect(reportKpi($pipeline, 'summary', 'open'))->toBe(1);
});

test('funnel counts a lead at every stage it reached and qualified uses the configured status', function () {
    $o = $this->org;
    ($this->at)('2026-09-10 10:00');
    $a = reportLead($o->rahul); // stays new
    $b = reportLead($o->rahul);
    moveLead($b, 'contacted', $o->rahul);
    $c = reportLead($o->rahul);
    moveLead($c, 'interested', $o->rahul);
    moveLead($c, 'lost', $o->rahul); // lost after reaching interested
    $d = reportLead($o->rahul);
    moveLead($d, 'negotiation', $o->rahul);
    moveLead($d, 'won', $o->rahul);

    ($this->at)('2026-09-15 10:00');
    $props = reportProps($this, $o->rahul, 'funnel', ['preset' => 'this_month']);
    $reached = reportRows($props, 'funnel')->pluck('reached', 'name');

    expect(reportKpi($props, 'summary', 'cohort'))->toBe(4)
        ->and($reached['New'])->toBe(4)
        ->and($reached['Contacted'])->toBe(3)
        ->and($reached['Interested'])->toBe(2)
        ->and($reached['Negotiation'])->toBe(1)
        ->and($reached['Won'])->toBe(1)
        ->and(reportKpi($props, 'summary', 'qualified'))->toBe(2)
        ->and(reportKpi($props, 'summary', 'won'))->toBe(1);
});

test('pipeline value discloses leads without a value instead of hiding them', function () {
    config(['crm.features.lead_value' => true]);
    ($this->at)('2026-09-15 10:00');
    reportLead($this->org->rahul, ['estimated_value' => 10000]);
    reportLead($this->org->rahul, ['estimated_value' => null]);
    moveLead(reportLead($this->org->rahul, ['estimated_value' => 99999]), 'won', $this->org->rahul);

    $props = reportProps($this, $this->org->rahul, 'pipeline', ['preset' => 'this_month']);
    expect(reportKpi($props, 'summary', 'open'))->toBe(2)
        ->and((float) reportKpi($props, 'summary', 'value'))->toBe(10000.0)
        ->and(reportKpi($props, 'summary', 'no_value'))->toBe(1)
        // New = 10 % probability.
        ->and((float) reportKpi($props, 'summary', 'weighted'))->toBe(1000.0);
});

test('ageing buckets and neglected reasons', function () {
    $o = $this->org;
    ($this->at)('2026-09-01 10:00');
    $old = reportLead($o->rahul);
    ($this->at)('2026-09-15 06:00');
    $fresh = reportLead($o->rahul);
    ($this->at)('2026-09-15 10:30'); // fresh is 4.5 h old → untouched

    $props = reportProps($this, $o->rahul, 'ageing', ['preset' => 'this_month']);
    $row = reportRows($props, 'by_status')->firstWhere('name', 'New');
    expect($row['0_1'])->toBe(1)->and($row['8_15'])->toBe(1)
        ->and(reportKpi($props, 'summary', 'open'))->toBe(2)
        ->and(reportKpi($props, 'neglect', 'untouched'))->toBe(2)
        ->and(reportKpi($props, 'neglect', 'no_next_action'))->toBe(2)
        ->and(reportKpi($props, 'neglect', 'inactive'))->toBe(1);

    // A scheduled follow-up clears "no next action".
    scheduleFollowup($old, $o->rahul, ['scheduled_date' => '2026-09-16']);
    $props = reportProps($this, $o->rahul, 'ageing', ['preset' => 'this_month']);
    expect(reportKpi($props, 'neglect', 'no_next_action'))->toBe(1);
});

test('archived leads are included by default and can be excluded', function () {
    ($this->at)('2026-09-15 10:00');
    reportLead($this->org->rahul);
    reportLead($this->org->rahul)->delete();

    expect(reportKpi(reportProps($this, $this->org->rahul, 'overview', ['preset' => 'today']), 'sales', 'new_leads'))->toBe(2)
        ->and(reportKpi(reportProps($this, $this->org->rahul, 'overview', ['preset' => 'today', 'archived' => 'exclude']), 'sales', 'new_leads'))->toBe(1);

    expect(Lead::withTrashed()->count())->toBe(2);
});

test('comparison shows N/A (null) rather than infinite growth when the previous period is empty', function () {
    ($this->at)('2026-09-15 10:00');
    reportLead($this->org->rahul);

    $props = reportProps($this, $this->org->rahul, 'overview', ['preset' => 'this_month', 'compare' => 1]);
    $kpi = collect(reportSection($props, 'sales')['items'])->firstWhere('key', 'new_leads');
    expect($kpi['previous'])->toBe(0)->and($kpi['change'])->toBeNull()
        // Month-to-date is compared with the same days of the previous month.
        ->and($props['comparison'])->toBe(['from' => '2026-08-01', 'to' => '2026-08-15']);
});

test('reports are read-only: viewing never changes operational data', function () {
    ($this->at)('2026-09-15 10:00');
    $lead = reportLead($this->org->rahul);
    $before = $lead->fresh()->toArray();

    foreach (['overview', 'pipeline', 'ageing', 'funnel', 'follow-ups', 'meetings'] as $slug) {
        reportProps($this, $this->org->manager, $slug, ['preset' => 'this_month']);
    }

    expect($lead->fresh()->toArray())->toBe($before)
        ->and(LeadStatusChange::count())->toBe(1);
});
