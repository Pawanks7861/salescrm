<?php

use App\Enums\AssignmentType;
use App\Models\Attachment;
use App\Models\Call;
use App\Models\Lead;
use App\Models\LeadAssignmentRule;
use App\Models\LeadNote;
use App\Models\LeadSource;
use App\Models\Permission;
use App\Models\User;
use App\Services\Leads\LeadService;
use App\Services\PermissionRegistrar;
use App\Services\Reports\ReportRegistry;
use App\Services\Reports\ReportScope;
use App\Support\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
| RELEASE-BLOCKING: access model without teams.
|   Super Admin / Admin → ALL (via *.view_all)
|   Everyone else       → OWN (leads.assigned_to = user); no team fallback
|   Unassigned leads    → Admin / Super Admin only
| salesOrg() still creates legacy teams, team_users and manager_id links on
| purpose: that data must grant nothing.
*/

beforeEach(function () {
    Storage::fake('local');
    $this->org = $org = salesOrg();
    $this->travelTo(CarbonImmutable::parse('2026-09-15 11:00', 'Asia/Kolkata'));
    telephonySetup([$org->rahul, $org->priya]);

    $this->world = [];
    foreach (['rahul' => 'RahulCustomer', 'priya' => 'PriyaCustomer'] as $key => $name) {
        $owner = $org->{$key};
        $lead = reportLead($owner, ['first_name' => $name, 'last_name' => 'Client']);

        $this->actingAs($owner)->post("/leads/{$lead->id}/notes", ['note' => "{$name} shared note", 'visibility' => 'team'])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post("/leads/{$lead->id}/attachments", ['file' => UploadedFile::fake()->create("{$name}.pdf", 50, 'application/pdf')])->assertSessionHasNoErrors();
        $lead->enquiries()->create(['channel' => 'facebook', 'external_id' => "enq-{$key}", 'enquiry_data_json' => ['budget' => "{$name} budget"], 'received_at' => now()]);

        $this->world[$key] = (object) [
            'lead' => $lead,
            'followup' => scheduleFollowup($lead, $owner, ['scheduled_date' => '2026-09-16', 'title' => "{$name} follow-up"]),
            'meeting' => scheduleMeeting($lead, $owner, ['scheduled_date' => '2026-09-17', 'title' => "{$name} meeting"]),
            'call' => finishCall($this, startCall($lead, $owner), 'completed', 60, "fake://recording/{$key}"),
            'note' => LeadNote::where('lead_id', $lead->id)->sole(),
            'attachment' => Attachment::where('attachable_id', $lead->id)->sole(),
        ];
    }
});

/** Every nested-resource URL of one owner's world. */
function accessUrls(object $w): array
{
    return [
        'lead' => "/leads/{$w->lead->id}",
        'lead activities' => "/leads/{$w->lead->id}/activities",
        'follow-up' => "/follow-ups/{$w->followup->id}",
        'meeting' => "/meetings/{$w->meeting->id}",
        'call' => "/calls/{$w->call->id}",
        'call status' => "/calls/{$w->call->id}/status",
        'recording' => "/calls/{$w->call->id}/recording",
        'note history' => "/leads/{$w->lead->id}/notes/{$w->note->id}/history",
        'attachment' => "/leads/{$w->lead->id}/attachments/{$w->attachment->id}/download",
    ];
}

/** Grants the listen/download permissions so only visibility can refuse access. */
function withFileAccess(User $user): User
{
    $user = setPermission($user, Permissions::CALL_RECORDING_LISTEN);

    return setPermission($user, 'file.download');
}

function assertNoAccess($test, User $user, object $w): void
{
    foreach (accessUrls($w) as $label => $url) {
        $status = $test->actingAs($user)->get($url)->getStatusCode();
        expect(in_array($status, [403, 404], true))->toBeTrue("{$user->name} reached {$label} ({$status})");
    }
}

function assertFullAccess($test, User $user, object $w): void
{
    foreach (accessUrls($w) as $label => $url) {
        $status = $test->actingAs($user)->get($url)->getStatusCode();
        expect($status)->toBe(200, "{$user->name} could not open {$label}");
    }
}

function listContent($test, User $user): string
{
    return collect(['/leads', '/leads/pipeline', '/follow-ups?tab=all', '/meetings?tab=all', '/calls', '/dashboard', '/calendar/events?start=2026-09-14T00:00:00&end=2026-09-21T00:00:00'])
        ->map(fn ($url) => $test->actingAs($user)->get($url)->assertOk()->getContent())
        ->implode("\n");
}

test('§38 Rahul and Priya are fully isolated across every lead resource', function () {
    $rahul = withFileAccess($this->org->rahul);
    $priya = withFileAccess($this->org->priya);

    assertFullAccess($this, $rahul, $this->world['rahul']);
    assertFullAccess($this, $priya, $this->world['priya']);
    assertNoAccess($this, $rahul, $this->world['priya']);
    assertNoAccess($this, $priya, $this->world['rahul']);

    // Lists, calendar, dashboard, pipeline.
    expect(listContent($this, $rahul))->toContain('RahulCustomer')->not->toContain('PriyaCustomer');
    expect(listContent($this, $priya))->toContain('PriyaCustomer')->not->toContain('RahulCustomer');

    // Enquiries and notes only ever travel inside the (authorized) lead page.
    $page = $this->actingAs($rahul)->get("/leads/{$this->world['rahul']->lead->id}")->assertOk()->getContent();
    expect($page)->toContain('RahulCustomer budget')->toContain('RahulCustomer shared note')
        ->not->toContain('PriyaCustomer budget')->not->toContain('PriyaCustomer shared note');

    // Search and duplicate check.
    $this->actingAs($rahul)->getJson('/search/leads?q=PriyaCustomer')->assertOk()->assertJsonCount(0, 'results');
    $this->actingAs($rahul)->getJson('/search/leads?q=RahulCustomer')->assertOk()->assertJsonCount(1, 'results');
    expect($this->actingAs($rahul)->postJson('/leads/duplicate-check', ['phone' => $this->world['priya']->lead->phone])->json('matches'))->toBe([]);

    // Reports: own numbers only, one performance row, no salesperson dropdown.
    $overview = reportProps($this, $rahul, 'overview', ['preset' => 'this_month']);
    expect(reportKpi($overview, 'sales', 'new_leads'))->toBe(1)
        ->and(reportKpi($overview, 'activity', 'calls'))->toBe(1)
        ->and($overview['options']['users'])->toBe([]);
    expect(reportRows(reportProps($this, $rahul, 'sales-performance', ['preset' => 'this_month']), 'performance')->pluck('name')->all())->toBe(['Rahul Sharma']);
    expect(json_encode($overview))->not->toContain('PriyaCustomer')->not->toContain('Priya Patel');

    // Nested writes are refused too.
    $p = $this->world['priya'];
    $this->actingAs($rahul)->post("/leads/{$p->lead->id}/notes", ['note' => 'x', 'visibility' => 'team'])->assertForbidden();
    $this->actingAs($rahul)->post("/follow-ups/{$p->followup->id}/complete", ['outcome' => 'connected'])->assertForbidden();
    $this->actingAs($rahul)->post("/meetings/{$p->meeting->id}/cancel", ['reason' => 'x'])->assertForbidden();
    $calls = Call::count();
    expect($this->actingAs($rahul)->postJson('/calls', ['lead_id' => $p->lead->id])->getStatusCode())->toBeIn([403, 404])
        ->and(Call::count())->toBe($calls);
});

test('§39 unassigned leads are visible to Admin and Super Admin only', function () {
    $unassigned = Lead::factory()->create(['first_name' => 'UnownedCustomer', 'assigned_to' => null, 'team_id' => $this->org->team->id]);

    foreach ([$this->org->rahul, $this->org->priya, $this->org->manager, $this->org->otherManager, $this->org->outsider] as $user) {
        $this->actingAs($user)->get("/leads/{$unassigned->id}")->assertForbidden();
        expect($this->actingAs($user)->get('/leads?assignee=unassigned')->getContent())->not->toContain('UnownedCustomer');
        expect($this->actingAs($user)->get('/dashboard')->inertiaProps('sales.unassigned'))->toBeNull();
    }

    foreach ([$this->org->admin, $this->org->super] as $user) {
        $this->actingAs($user)->get("/leads/{$unassigned->id}")->assertOk();
        expect(collect($this->actingAs($user)->get('/leads?assignee=unassigned')->inertiaProps('leads.data'))->pluck('id')->all())->toBe([$unassigned->id])
            ->and($this->actingAs($user)->get('/dashboard')->inertiaProps('sales.unassigned'))->toBe(1);
    }
});

test('§40 reassignment moves every nested resource to the new owner immediately', function () {
    $rahul = withFileAccess($this->org->rahul);
    $priya = withFileAccess($this->org->priya);
    $w = $this->world['rahul'];

    $this->actingAs($this->org->admin)->post("/leads/{$w->lead->id}/assign", ['assigned_to' => $priya->id])->assertRedirect();

    // The old owner loses the lead and everything under it — even the follow-up
    // still assigned to him, the meeting he hosts and the call he made.
    assertNoAccess($this, $rahul, $w);
    expect(listContent($this, $rahul))->not->toContain('RahulCustomer');
    expect($this->actingAs($rahul)->get("/leads/{$w->lead->id}")->inertiaProps('context'))->toBe('lead');

    // The new owner gains all of it.
    assertFullAccess($this, $priya, $w);
    expect(listContent($this, $priya))->toContain('RahulCustomer');

    // Reassigning back restores Rahul's access; history is kept.
    $this->actingAs($this->org->admin)->post("/leads/{$w->lead->id}/assign", ['assigned_to' => $rahul->id])->assertRedirect();
    assertFullAccess($this, $rahul, $w);
    assertNoAccess($this, $priya, $w);
});

test('§41 a Sales Manager with legacy team data sees nothing of their former team', function () {
    $manager = withFileAccess($this->org->manager);
    // Stamp legacy team ids on every nested record as pre-upgrade data would have.
    foreach ($this->world as $w) {
        foreach ([$w->lead, $w->followup, $w->meeting, $w->call] as $model) {
            $model->forceFill(['team_id' => $this->org->team->id])->saveQuietly();
        }
    }
    expect($this->org->rahul->fresh()->manager_id)->toBe($manager->id);

    assertNoAccess($this, $manager, $this->world['rahul']);
    assertNoAccess($this, $manager, $this->world['priya']);
    expect(listContent($this, $manager))->not->toContain('RahulCustomer')->not->toContain('PriyaCustomer');

    $overview = reportProps($this, $manager, 'overview', ['preset' => 'this_month']);
    expect(reportKpi($overview, 'sales', 'new_leads'))->toBe(0)->and($overview['options']['users'])->toBe([]);
    expect(reportRows(reportProps($this, $manager, 'sales-performance', ['preset' => 'this_month']), 'performance')->pluck('name')->all())->toBe(['Mehul Manager']);

    // The manager still works their own leads normally.
    $own = reportLead($manager, ['first_name' => 'ManagerCustomer']);
    $this->actingAs($manager)->get("/leads/{$own->id}")->assertOk();

    // Wider access only ever comes from an explicit permission.
    $granted = setPermission($manager, Permissions::LEAD_VIEW_ALL);
    $this->actingAs($granted)->get("/leads/{$this->world['priya']->lead->id}")->assertOk();
});

test('§42 old view_team / team grants in the database grant nothing', function () {
    $legacy = [...Permissions::DEPRECATED];
    $manager = $this->org->manager;
    foreach ($legacy as $name) {
        $manager = legacyRoleGrant($manager, $name);
    }
    // Direct user overrides as well.
    $rahul = $this->org->rahul;
    $rahul->permissionOverrides()->syncWithoutDetaching(Permission::whereIn('name', $legacy)->pluck('id')->mapWithKeys(fn ($id) => [$id => ['type' => 'grant']])->all());
    app(PermissionRegistrar::class)->flushAll();
    $rahul = $rahul->fresh();

    foreach ([$manager, $rahul] as $user) {
        foreach ($legacy as $name) {
            expect($user->hasPermission($name))->toBeFalse("{$user->name} holds {$name}");
        }
        expect(ReportScope::for($user)->tier)->toBe(ReportScope::OWN);
        $this->actingAs($user)->get('/admin/teams')->assertNotFound();
    }

    assertNoAccess($this, $manager, $this->world['rahul']);
    assertNoAccess($this, $rahul, $this->world['priya']);
    expect(listContent($this, $rahul))->not->toContain('PriyaCustomer');

    // The catalogue no longer offers them, so the role matrix cannot re-grant them.
    expect(array_intersect(array_keys(Permissions::all()), $legacy))->toBe([]);
});

test('§43 reports have no team filter, team option, team aggregate or team report', function () {
    foreach ([$this->org->admin, $this->org->super, $this->org->manager, $this->org->rahul] as $user) {
        $slugs = collect(app(ReportRegistry::class)->available(ReportScope::for($user)))->flatten(1)->pluck('slug')->filter();
        expect($slugs)->not->toContain('teams');
        $this->actingAs($user)->get('/reports/teams')->assertNotFound();

        $props = reportProps($this, $user, 'sales-performance', ['preset' => 'this_month', 'team' => $this->org->team->id]);
        expect($props['options'])->not->toHaveKey('teams')
            ->and($props['filters'])->not->toHaveKey('team')
            ->and(json_encode($props))->not->toContain('Ahmedabad Team')->not->toContain('Mumbai Team');
    }

    // Admin gets a salesperson filter covering everyone.
    $admin = reportProps($this, $this->org->admin, 'overview', ['preset' => 'this_month', 'user' => $this->org->priya->id]);
    expect(collect($admin['options']['users'])->pluck('label')->all())->toContain('Rahul Sharma', 'Priya Patel', 'Outside Exec')
        ->and(reportKpi($admin, 'sales', 'new_leads'))->toBe(1);
});

test('§44 team assignment rules never run; user rules and user round robin do', function () {
    $facebook = LeadSource::where('slug', 'facebook')->value('id');
    $rule = fn (array $a) => tap(new LeadAssignmentRule(['name' => 'Rule', 'is_active' => true, 'condition_type' => 'any', ...$a]))->save();

    $rule(['priority' => 1, 'assignment_type' => 'team', 'assigned_team_id' => $this->org->team->id]);
    $rule(['priority' => 2, 'assignment_type' => 'team_round_robin', 'assigned_team_id' => $this->org->team->id]);
    $rule(['priority' => 3, 'condition_type' => 'team', 'condition_value' => (string) $this->org->team->id, 'assignment_type' => 'user', 'assigned_user_id' => $this->org->outsider->id]);
    $rule(['priority' => 10, 'assignment_type' => 'round_robin', 'user_pool_json' => [$this->org->rahul->id, $this->org->priya->id]]);

    $owners = collect(range(1, 4))->map(fn ($i) => app(LeadService::class)->createFromInbound([
        'first_name' => "Inbound{$i}", 'phone' => '97'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), 'source_id' => $facebook,
    ], AssignmentType::Facebook)['lead']);

    expect($owners->pluck('assigned_to')->all())->toBe([$this->org->rahul->id, $this->org->priya->id, $this->org->rahul->id, $this->org->priya->id])
        ->and($owners->pluck('team_id')->filter()->all())->toBe([]);

    // Only the new owner is notified; a team manager hears nothing.
    expect($this->org->manager->notifications()->count())->toBe(0);
});
