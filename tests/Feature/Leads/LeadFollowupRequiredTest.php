<?php

use App\Enums\FollowupStatus;
use App\Models\Followup;
use App\Models\Lead;
use App\Models\User;
use App\Services\Leads\LeadFollowupRequiredQuery;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->org = salesOrg();
    $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00', 'Asia/Kolkata'));
});

test('leads created before 1 october 2026 never qualify', function () {
    $old = attentionLead($this->org->rahul, '2026-09-30 23:59:00');
    $onCutoff = attentionLead($this->org->rahul, '2026-10-01 00:00:00');
    attentionFollowups($onCutoff, completed: 1);

    $earlyCompleted = attentionLead($this->org->rahul, '2026-09-25 10:00:00');
    attentionFollowups($earlyCompleted, completed: 1);

    $ids = attentionIds($this->org->admin);

    expect($ids['all'])->not->toContain($old->id)
        ->and($ids['none'])->not->toContain($old->id)
        ->and($ids['missing'])->not->toContain($old->id)
        ->and($ids['all'])->not->toContain($earlyCompleted->id)
        ->and($ids['missing'])->toContain($onCutoff->id);
});

test('category A includes a lead only after five full days with no follow-up', function () {
    $lead = attentionLead($this->org->rahul, '2026-10-01 10:00:00');

    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'Asia/Kolkata'));
    expect(attentionIds($this->org->admin)['none'])->not->toContain($lead->id);

    $this->travelTo(CarbonImmutable::parse('2026-10-06 09:59:00', 'Asia/Kolkata'));
    expect(attentionIds($this->org->admin)['none'])->not->toContain($lead->id);

    $this->travelTo(CarbonImmutable::parse('2026-10-06 10:00:00', 'Asia/Kolkata'));
    expect(attentionIds($this->org->admin)['none'])->toContain($lead->id)
        ->and(attentionIds($this->org->admin)['missing'])->not->toContain($lead->id);
});

test('a lead that is exactly five full days old with no follow-up is shown', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 15:00:00', 'Asia/Kolkata'));
    $exact = attentionLead($this->org->rahul, '2026-10-05 15:00:00');
    $justShort = attentionLead($this->org->rahul, '2026-10-05 15:00:01');
    $fourDays = attentionLead($this->org->rahul, '2026-10-06 15:00:00');

    $none = attentionIds($this->org->admin)['none'];

    expect($none)->toContain($exact->id)
        ->and($none)->not->toContain($justShort->id)
        ->and($none)->not->toContain($fourDays->id);
});

test('any follow-up keeps a lead out of the five day category', function () {
    $lead = attentionLead($this->org->rahul, '2026-10-01 09:00:00');
    attentionFollowups($lead, pending: 1);

    $ids = attentionIds($this->org->admin);

    expect($ids['none'])->not->toContain($lead->id)
        ->and($ids['missing'])->not->toContain($lead->id)
        ->and($ids['all'])->not->toContain($lead->id);
});

test('category B shows one or two completed follow-ups only when nothing is pending', function () {
    $one = attentionLead($this->org->rahul, '2026-10-04 11:00:00');
    attentionFollowups($one, completed: 1);

    $two = attentionLead($this->org->rahul, '2026-10-03 11:00:00');
    attentionFollowups($two, completed: 2);

    $onePending = attentionLead($this->org->rahul, '2026-10-02 11:00:00');
    attentionFollowups($onePending, completed: 1, pending: 1);

    $twoPending = attentionLead($this->org->rahul, '2026-10-02 12:00:00');
    attentionFollowups($twoPending, completed: 2, pending: 1);

    $three = attentionLead($this->org->rahul, '2026-10-02 13:00:00');
    attentionFollowups($three, completed: 3);

    $cancelledOnly = attentionLead($this->org->rahul, '2026-10-01 08:00:00');
    attentionFollowups($cancelledOnly, cancelled: 1);

    $rescheduledOnly = attentionLead($this->org->rahul, '2026-10-01 07:00:00');
    attentionFollowups($rescheduledOnly, rescheduled: 1);

    $completedAndCancelled = attentionLead($this->org->rahul, '2026-10-04 16:00:00');
    attentionFollowups($completedAndCancelled, completed: 1, cancelled: 1);

    $ids = attentionIds($this->org->admin);

    expect($ids['missing'])->toContain($one->id)
        ->and($ids['missing'])->toContain($two->id)
        ->and($ids['missing'])->toContain($completedAndCancelled->id)
        ->and($ids['missing'])->not->toContain($onePending->id)
        ->and($ids['missing'])->not->toContain($twoPending->id)
        ->and($ids['missing'])->not->toContain($three->id)
        ->and($ids['missing'])->not->toContain($cancelledOnly->id)
        ->and($ids['missing'])->not->toContain($rescheduledOnly->id)
        ->and($ids['none'])->not->toContain($cancelledOnly->id)
        ->and($ids['none'])->not->toContain($rescheduledOnly->id);
});

test('won and lost leads are excluded', function () {
    $won = attentionLead($this->org->rahul, '2026-10-01 10:00:00', ['status_id' => leadStatusId('won')]);
    $lost = attentionLead($this->org->rahul, '2026-10-01 11:00:00', ['status_id' => leadStatusId('lost')]);
    attentionFollowups($lost, completed: 1);

    $ids = attentionIds($this->org->admin);

    expect($ids['all'])->not->toContain($won->id)
        ->and($ids['all'])->not->toContain($lost->id);
});

test('archived leads are excluded', function () {
    $lead = attentionLead($this->org->rahul, '2026-10-01 10:00:00');
    $lead->delete();

    expect(attentionIds($this->org->admin)['all'])->not->toContain($lead->id);
});

test('own visibility hides another salesperson lead and view all includes it', function () {
    $mine = attentionLead($this->org->rahul, '2026-10-01 10:00:00');
    $theirs = attentionLead($this->org->priya, '2026-10-01 10:00:00');

    $rahul = attentionIds($this->org->rahul);
    $admin = attentionIds($this->org->admin);

    expect($rahul['all'])->toContain($mine->id)
        ->and($rahul['all'])->not->toContain($theirs->id)
        ->and($admin['all'])->toContain($mine->id)
        ->and($admin['all'])->toContain($theirs->id);
});

test('a user without lead view cannot open the page', function () {
    $this->actingAs(User::factory()->trainer()->create())
        ->get('/leads/follow-up-required')
        ->assertForbidden();
});

test('the dashboard lead total counts only leads with no follow-up from 1 october 2026', function () {
    attentionLead($this->org->rahul, '2026-10-02 10:00:00');
    attentionLead($this->org->rahul, '2026-09-20 10:00:00');
    $withFollowup = attentionLead($this->org->priya, '2026-10-03 10:00:00');
    attentionFollowups($withFollowup, completed: 1);
    attentionLead($this->org->admin, '2026-10-09 10:00:00');
    Lead::factory()->create(['created_at' => ist('2026-10-04 10:00:00'), 'updated_at' => ist('2026-10-04 10:00:00')]);
    Lead::factory()->create(['created_at' => ist('2026-09-15 10:00:00'), 'updated_at' => ist('2026-09-15 10:00:00')]);

    $admin = $this->actingAs($this->org->admin)->get('/dashboard')->assertOk()->inertiaProps('sales');
    $rahul = $this->actingAs($this->org->rahul)->get('/dashboard')->assertOk()->inertiaProps('sales');

    expect($admin['leads'])->toBe(3)
        ->and($admin['unassigned'])->toBe(1)
        ->and($rahul['leads'])->toBe(1)
        ->and($rahul['unassigned'])->toBeNull();
});

test('the dashboard count is the distinct total and names the page', function () {
    attentionLead($this->org->rahul, '2026-10-01 10:00:00');
    $missing = attentionLead($this->org->priya, '2026-10-04 10:00:00');
    attentionFollowups($missing, completed: 1);
    attentionLead($this->org->rahul, '2026-09-20 10:00:00');

    $card = $this->actingAs($this->org->admin)->get('/dashboard')->assertOk()->inertiaProps('followupRequired');
    $page = $this->actingAs($this->org->admin)->get('/leads/follow-up-required')->assertOk();

    expect($card['total'])->toBe(2)
        ->and($card['none'])->toBe(1)
        ->and($card['missing'])->toBe(1)
        ->and($card['total'])->toBe($card['none'] + $card['missing'])
        ->and($page->inertiaProps('counts'))->toMatchArray(['all' => 2, 'none' => 1, 'missing' => 1])
        ->and($page->inertiaProps('leads.total'))->toBe(2)
        ->and(route('leads.follow-up-required', absolute: false))->toBe('/leads/follow-up-required');

    $own = $this->actingAs($this->org->rahul)->get('/dashboard')->inertiaProps('followupRequired');
    expect($own['total'])->toBe(1)
        ->and($own['none'])->toBe(1)
        ->and($own['missing'])->toBe(0);
});

test('a trainer dashboard does not include the follow-up required count', function () {
    expect($this->actingAs(User::factory()->trainer()->create())->get('/dashboard')->assertOk()->inertiaProps('followupRequired'))->toBeNull();
});

test('search and pagination keep the qualifying set', function () {
    $match = attentionLead($this->org->rahul, '2026-10-01 10:00:00', ['first_name' => 'Zebra', 'last_name' => 'Unique']);
    attentionLead($this->org->rahul, '2026-10-01 11:00:00', ['first_name' => 'Other', 'last_name' => 'Person']);

    $found = collect($this->actingAs($this->org->admin)->get('/leads/follow-up-required?search=Zebra')->assertOk()->inertiaProps('leads.data'))->pluck('id');
    expect($found->all())->toBe([$match->id]);

    foreach (range(1, 26) as $i) {
        attentionLead($this->org->admin, '2026-10-01 09:00:00', ['first_name' => 'Page', 'last_name' => 'Lead'.$i]);
    }

    $page = $this->actingAs($this->org->admin)->get('/leads/follow-up-required?tab=none&search=Page')->assertOk();
    expect($page->inertiaProps('leads.total'))->toBe(26)
        ->and($page->inertiaProps('leads.data'))->toHaveCount(25)
        ->and($page->inertiaProps('counts.none'))->toBe(26);

    $next = $this->actingAs($this->org->admin)->get('/leads/follow-up-required?tab=none&search=Page&page=2')->assertOk();
    expect($next->inertiaProps('leads.data'))->toHaveCount(1)
        ->and($next->inertiaProps('filters.tab'))->toBe(LeadFollowupRequiredQuery::TAB_NONE)
        ->and($next->inertiaProps('filters.search'))->toBe('Page');
});

test('assignee and status filters stay on the server', function () {
    $rahulNew = attentionLead($this->org->rahul, '2026-10-01 10:00:00');
    $priyaContacted = attentionLead($this->org->priya, '2026-10-01 11:00:00', ['status_id' => leadStatusId('contacted')]);

    $byOwner = collect($this->actingAs($this->org->admin)
        ->get('/leads/follow-up-required?assignee='.$this->org->rahul->id)
        ->assertOk()
        ->inertiaProps('leads.data'))->pluck('id');

    $byStatus = collect($this->actingAs($this->org->admin)
        ->get('/leads/follow-up-required?status='.leadStatusId('contacted'))
        ->assertOk()
        ->inertiaProps('leads.data'))->pluck('id');

    expect($byOwner->all())->toBe([$rahulNew->id])
        ->and($byStatus->all())->toBe([$priyaContacted->id]);
});

test('category rows report completed follow-up count and the latest completion', function () {
    $none = attentionLead($this->org->rahul, '2026-10-01 10:00:00');
    $done = attentionLead($this->org->rahul, '2026-10-04 10:00:00');
    attentionFollowups($done, completed: 2, completedAt: '2026-10-08 11:30:00');

    $rows = collect($this->actingAs($this->org->admin)->get('/leads/follow-up-required')->assertOk()->inertiaProps('leads.data'))->keyBy('id');

    expect($rows[$none->id]['completed_followups_count'])->toBe(0)
        ->and($rows[$none->id]['last_followup_at'])->toBeNull()
        ->and($rows[$none->id]['next_followup_at'])->toBeNull()
        ->and($rows[$done->id]['completed_followups_count'])->toBe(2)
        ->and($rows[$done->id]['last_followup_at'])->not->toBeNull();
});

function attentionLead(User $owner, string $local, array $overrides = []): Lead
{
    return Lead::factory()->assignedTo($owner)->create([
        'created_at' => ist($local),
        'updated_at' => ist($local),
        ...$overrides,
    ]);
}

function attentionFollowups(Lead $lead, int $completed = 0, int $pending = 0, int $cancelled = 0, int $rescheduled = 0, ?string $completedAt = null): void
{
    for ($i = 1; $i <= $completed; $i++) {
        $at = ist($completedAt ?? '2026-10-08 11:00:00')->addMinutes($i);
        Followup::factory()->forLead($lead)->status(FollowupStatus::Completed)->create([
            'completed_at' => $at,
            'scheduled_at' => $at,
        ]);
    }
    for ($i = 0; $i < $pending; $i++) {
        Followup::factory()->forLead($lead)->create([
            'scheduled_at' => ist('2026-10-12 11:00:00'),
        ]);
    }
    for ($i = 0; $i < $cancelled; $i++) {
        Followup::factory()->forLead($lead)->status(FollowupStatus::Cancelled)->create();
    }
    for ($i = 0; $i < $rescheduled; $i++) {
        Followup::factory()->forLead($lead)->status(FollowupStatus::Rescheduled)->create();
    }
}

/** @return array{all: array<int>, none: array<int>, missing: array<int>} */
function attentionIds(User $user): array
{
    $ids = function (string $tab) use ($user) {
        return collect(test()->actingAs($user)->get('/leads/follow-up-required?tab='.$tab)->assertOk()->inertiaProps('leads.data'))->pluck('id')->all();
    };

    return [
        'all' => $ids('all'),
        'none' => $ids('none'),
        'missing' => $ids('missing'),
    ];
}
