<?php

use App\Models\Call;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\Calls\MissedCallNotification;
use App\Services\Leads\LeadAssignmentService;
use App\Services\Telephony\CallVisibility;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->org = salesOrg();
    $this->integration = telephonySetup([$this->org->rahul, $this->org->priya, $this->org->outsider]);
    $this->rahulLead = Lead::factory()->assignedTo($this->org->rahul)->create(['full_name' => 'Amit Desai', 'phone' => '9876543210', 'normalized_phone' => '919876543210']);
    $this->priyaLead = Lead::factory()->assignedTo($this->org->priya)->create(['full_name' => 'Neha Joshi', 'phone' => '9876500000', 'normalized_phone' => '919876500000']);
    $this->outsiderLead = Lead::factory()->assignedTo($this->org->outsider)->create(['full_name' => 'Karan Mehta', 'phone' => '9811100000', 'normalized_phone' => '919811100000']);

    $this->rahulCall = startCall($this->rahulLead, $this->org->rahul);
    $this->priyaCall = startCall($this->priyaLead, $this->org->priya);
    $this->outsiderCall = startCall($this->outsiderLead, $this->org->outsider);
});

function visibleCallIds(User $user): array
{
    return Call::query()->visibleTo($user)->orderBy('id')->pluck('id')->all();
}

test('sales executives see only their own calls', function () {
    expect(visibleCallIds($this->org->rahul))->toBe([$this->rahulCall->id]);

    $this->actingAs($this->org->rahul)->get(route('calls.show', $this->rahulCall))->assertOk();
    $this->actingAs($this->org->rahul)->get(route('calls.show', $this->priyaCall))->assertForbidden();
    $this->actingAs($this->org->rahul)->get(route('calls.show', $this->outsiderCall))->assertForbidden();
});

test('managers get no call access through legacy team membership', function () {
    $this->rahulCall->forceFill(['team_id' => $this->org->team->id])->save();

    expect(visibleCallIds($this->org->manager))->toBe([])
        ->and(visibleCallIds($this->org->otherManager))->toBe([]);

    $this->actingAs($this->org->manager)->get(route('calls.show', $this->rahulCall))->assertForbidden();
    $this->actingAs($this->org->otherManager)->get(route('calls.show', $this->outsiderCall))->assertForbidden();
});

test('admins see every call', function () {
    expect(visibleCallIds($this->org->admin))->toHaveCount(3)
        ->and(visibleCallIds($this->org->super))->toHaveCount(3);
});

test('the calls list is filtered in SQL and paginated', function () {
    $this->actingAs($this->org->rahul)->get(route('calls.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Calls/Index')
            ->where('calls.total', 1)
            ->has('calls.data', 1)
            ->where('calls.data.0.id', $this->rahulCall->id)
            ->where('can.filterByUser', false)
            ->where('can.manualDial', false));
});

test('filtering by another user never widens visibility', function () {
    $this->actingAs($this->org->rahul)->get(route('calls.index', ['agent' => $this->org->outsider->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('calls.total', 0));

    $this->actingAs($this->org->rahul)->get(route('calls.index', ['team' => $this->org->team->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('calls.total', 1)->missing('filters.team'));
});

test('search treats LIKE wildcards literally', function () {
    $this->actingAs($this->org->admin)->get(route('calls.index', ['search' => '%']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('calls.total', 0));

    $this->actingAs($this->org->admin)->get(route('calls.index', ['search' => 'Amit']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('calls.total', 1));
});

test('reassigning the lead removes the previous agent access to its calls', function () {
    app(LeadAssignmentService::class)->assignManually($this->rahulLead, $this->org->admin, $this->org->priya->id, 'Rebalance');

    expect(app(CallVisibility::class)->canView($this->org->rahul->fresh(), $this->rahulCall->fresh()))->toBeFalse();
    $this->actingAs($this->org->rahul)->get(route('calls.show', $this->rahulCall))->assertForbidden();
    expect(visibleCallIds($this->org->rahul))->toBe([]);
});

test('archived leads hide their calls from everyone below view_all', function () {
    $this->rahulLead->delete();

    expect(visibleCallIds($this->org->rahul))->toBe([])
        ->and(visibleCallIds($this->org->priya))->toBe([$this->priyaCall->id])
        ->and(visibleCallIds($this->org->admin))->not->toContain($this->rahulCall->id);
});

test('status polling is authorized like the call itself', function () {
    $this->actingAs($this->org->rahul)->getJson(route('calls.status', $this->rahulCall))->assertOk()->assertJsonPath('call.id', $this->rahulCall->id);
    $this->actingAs($this->org->rahul)->getJson(route('calls.status', $this->priyaCall))->assertForbidden();
});

test('users without call permissions cannot open the calls module', function () {
    setPermission($this->org->rahul, 'call.view', 'deny');

    $this->actingAs($this->org->rahul->fresh())->get(route('calls.index'))->assertForbidden();
});

test('call notifications for calls that are no longer visible are shown as stale', function () {
    $this->org->rahul->notify(new MissedCallNotification($this->rahulCall));

    $this->actingAs($this->org->rahul)->getJson(route('notifications.recent'))
        ->assertOk()->assertJsonPath('data.0.stale', false);

    app(LeadAssignmentService::class)->assignManually($this->rahulLead, $this->org->admin, $this->org->priya->id, 'Rebalance');

    $response = $this->actingAs($this->org->rahul->fresh())->getJson(route('notifications.recent'))->assertOk();
    expect($response->json('data.0.stale'))->toBeTrue()
        ->and($response->json('data.0.target'))->toBeNull()
        ->and($response->getContent())->not->toContain('Amit Desai');
});

test('incoming identification returns the matched visible lead', function () {
    $this->actingAs($this->org->rahul)->postJson(route('telephony.identify'), ['number' => '+91 98765 43210'])
        ->assertOk()
        ->assertJsonPath('state', 'matched')
        ->assertJsonPath('leads.0.id', $this->rahulLead->id)
        ->assertJsonPath('leads.0.name', 'Amit Desai');
});

test('incoming identification for a lead the user cannot see reveals nothing', function () {
    $response = $this->actingAs($this->org->rahul)->postJson(route('telephony.identify'), ['number' => '+91 98111 00000'])->assertOk();

    expect($response->json('state'))->toBe('restricted')
        ->and($response->json('message'))->toBe('Lead information unavailable.')
        ->and($response->json('leads'))->toBe([])
        ->and($response->getContent())->not->toContain('Karan')
        ->and($response->getContent())->not->toContain('Outside Exec');
});

test('incoming identification lists multiple visible matches', function () {
    Lead::factory()->assignedTo($this->org->rahul)->create(['full_name' => 'Amit Desai Jr', 'alternate_phone' => '9876543210', 'normalized_alternate_phone' => '919876543210']);

    $this->actingAs($this->org->rahul)->postJson(route('telephony.identify'), ['number' => '9876543210'])
        ->assertOk()->assertJsonPath('state', 'multiple')->assertJsonCount(2, 'leads');
});

test('incoming identification of an unknown number offers lead creation without creating one', function () {
    $this->actingAs($this->org->rahul)->postJson(route('telephony.identify'), ['number' => '+91 90000 12345'])
        ->assertOk()->assertJsonPath('state', 'unknown')->assertJsonPath('can_create_lead', true);

    expect(Lead::count())->toBe(3);
});
