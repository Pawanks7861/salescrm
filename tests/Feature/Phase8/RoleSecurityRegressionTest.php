<?php

use App\Models\Lead;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Storage;

/*
| Phase 8 §76 / §91: release-blocking role boundaries, re-verified as one
| regression suite. Roles and permissions are unchanged by Phase 8.
*/

beforeEach(function () {
    $this->org = salesOrg();
    $this->rahulLead = Lead::factory()->assignedTo($this->org->rahul)->create();
    $this->priyaLead = Lead::factory()->assignedTo($this->org->priya)->create();
    $this->mumbaiLead = Lead::factory()->assignedTo($this->org->outsider)->create();
});

test('sales executives cannot reach administration', function (string $url) {
    $this->actingAs($this->org->rahul)->get($url)->assertForbidden();
})->with(['/admin/users', '/admin/roles', '/admin/audit-logs', '/admin/login-history', '/admin/settings', '/admin/integrations/telephony', '/admin/integrations/facebook']);

test('sales executives only see their own leads', function () {
    $this->actingAs($this->org->rahul)->get("/leads/{$this->rahulLead->id}")->assertOk();
    $this->actingAs($this->org->rahul)->get("/leads/{$this->priyaLead->id}")->assertForbidden();
    $this->actingAs($this->org->rahul)->get("/leads/{$this->mumbaiLead->id}")->assertForbidden();
});

test('managers get no team visibility; admins see every lead', function () {
    foreach ([$this->rahulLead, $this->priyaLead, $this->mumbaiLead] as $lead) {
        $this->actingAs($this->org->manager)->get("/leads/{$lead->id}")->assertForbidden();
        $this->actingAs($this->org->admin)->get("/leads/{$lead->id}")->assertOk();
    }
    $this->actingAs($this->org->admin)->get('/admin/teams')->assertNotFound();
});

test('sales executives can never export or download', function () {
    Storage::fake('local');

    $this->actingAs($this->org->rahul)->from('/reports/overview')
        ->post('/reports/overview/export', ['section' => 'sources', 'filters' => ['preset' => 'this_month']])
        ->assertForbidden();

    expect($this->org->rahul->hasPermission('report.export'))->toBeFalse()
        ->and($this->org->rahul->hasPermission('file.download'))->toBeFalse()
        ->and($this->org->rahul->hasPermission('call.recording.download'))->toBeFalse()
        ->and($this->org->rahul->hasPermission('lead.export'))->toBeFalse();
});

test('admins cannot manage roles, Facebook or telephony, and only Super Admin can', function () {
    $this->actingAs($this->org->admin)->get('/admin/integrations/facebook')->assertForbidden();
    $this->actingAs($this->org->admin)->get('/admin/integrations/telephony')->assertForbidden();
    $this->actingAs($this->org->super)->get('/admin/integrations/telephony')->assertOk();
    expect($this->org->admin->hasPermission('role.manage'))->toBeFalse()
        ->and($this->org->admin->hasPermission('facebook.manage'))->toBeFalse()
        ->and($this->org->admin->hasPermission('call.configure'))->toBeFalse()
        ->and($this->org->super->can('role.manage'))->toBeTrue()
        ->and($this->org->super->can('call.configure'))->toBeTrue()
        ->and($this->org->super->can('facebook.manage'))->toBeTrue();
});

test('managers cannot read the audit log or change settings', function () {
    $this->actingAs($this->org->manager)->get('/admin/audit-logs')->assertForbidden();
    $this->withoutMiddleware(ValidateCsrfToken::class)
        ->actingAs($this->org->manager)->put('/admin/settings/general', ['settings' => ['general' => ['company_name' => 'Hacked']]])
        ->assertForbidden();
});

test('guests are redirected to login everywhere', function (string $url) {
    $this->get($url)->assertRedirect('/login');
})->with(['/dashboard', '/leads', '/follow-ups', '/meetings', '/calls', '/reports/overview', '/admin/users']);

test('deactivated users are logged out on their next request', function () {
    $this->org->rahul->forceFill(['is_active' => false])->save();

    $this->actingAs($this->org->rahul)->get('/dashboard')->assertRedirect('/login');
    $this->assertGuest();
});
