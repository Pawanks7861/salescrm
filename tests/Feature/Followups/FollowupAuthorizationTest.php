<?php

use App\Models\Followup;
use App\Models\Lead;
use App\Models\Permission;
use App\Services\PermissionRegistrar;
use App\Support\Permissions;

beforeEach(function () {
    $this->org = salesOrg();
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create();
    $this->followup = scheduleFollowup($this->lead, $this->org->rahul);
});

function denyPermission($user, string $permission): void
{
    $user->permissionOverrides()->attach(Permission::where('name', $permission)->value('id'), ['type' => 'deny']);
    app(PermissionRegistrar::class)->flushUser($user);
}

test('guests are redirected to login', function () {
    $this->get('/follow-ups')->assertRedirect('/login');
    $this->post("/follow-ups/{$this->followup->id}/complete")->assertRedirect('/login');
});

test('users without any follow-up view permission cannot open the module', function () {
    denyPermission($this->org->rahul, Permissions::FOLLOWUP_VIEW);

    $this->actingAs($this->org->rahul)->get('/follow-ups')->assertForbidden();
    $this->actingAs($this->org->rahul)->get("/follow-ups/{$this->followup->id}")->assertForbidden();
});

test('each action requires its own permission', function () {
    $id = $this->followup->id;
    $rahul = $this->org->rahul;

    denyPermission($rahul, Permissions::FOLLOWUP_CREATE);
    $this->actingAs($rahul)->post('/follow-ups', followupPayload($this->lead, ['scheduled_time' => '15:00']))->assertForbidden();

    denyPermission($rahul, Permissions::FOLLOWUP_COMPLETE);
    $this->actingAs($rahul)->post("/follow-ups/{$id}/complete", ['outcome' => 'connected'])->assertForbidden();

    denyPermission($rahul, Permissions::FOLLOWUP_CANCEL);
    $this->actingAs($rahul)->post("/follow-ups/{$id}/cancel", ['reason' => 'x'])->assertForbidden();

    denyPermission($rahul, Permissions::FOLLOWUP_EDIT);
    $this->actingAs($rahul)->put("/follow-ups/{$id}", ['title' => 'x'])->assertForbidden();
    $this->actingAs($rahul)->post("/follow-ups/{$id}/reschedule", crmSlot(now()->addDays(3)))->assertForbidden();

    expect($this->followup->fresh()->isPending())->toBeTrue();
});

test('sales executives and managers without scope cannot delete follow-ups; admins can delete and restore', function () {
    $this->actingAs($this->org->rahul)->delete("/follow-ups/{$this->followup->id}")->assertForbidden();

    $this->actingAs($this->org->otherManager)->delete("/follow-ups/{$this->followup->id}")->assertForbidden();
    $this->actingAs($this->org->manager)->delete("/follow-ups/{$this->followup->id}")->assertForbidden();

    $this->actingAs($this->org->admin)->delete("/follow-ups/{$this->followup->id}")->assertRedirect();
    expect(Followup::count())->toBe(0)->and(Followup::withTrashed()->count())->toBe(1);

    $this->actingAs($this->org->rahul)->get("/follow-ups/{$this->followup->id}")->assertForbidden();
    $this->actingAs($this->org->admin)->get("/follow-ups/{$this->followup->id}")->assertOk();

    $this->actingAs($this->org->admin)->post("/follow-ups/{$this->followup->id}/restore")->assertRedirect();
    expect(Followup::count())->toBe(1);
});

test('closed follow-ups cannot be edited, completed, rescheduled or cancelled again', function () {
    $id = $this->followup->id;
    $this->actingAs($this->org->rahul)->post("/follow-ups/{$id}/complete", ['outcome' => 'connected'])->assertSessionHasNoErrors();

    $this->actingAs($this->org->rahul)->put("/follow-ups/{$id}", ['title' => 'x'])->assertForbidden();
    $this->actingAs($this->org->rahul)->post("/follow-ups/{$id}/complete", ['outcome' => 'connected'])->assertForbidden();
    $this->actingAs($this->org->rahul)->post("/follow-ups/{$id}/reschedule", crmSlot(now()->addDays(3)))->assertForbidden();
    $this->actingAs($this->org->rahul)->post("/follow-ups/{$id}/cancel", ['reason' => 'x'])->assertForbidden();
});

test('follow-up settings are restricted to followup.configure', function () {
    $this->actingAs($this->org->rahul)->get('/admin/followup-settings')->assertForbidden();
    $this->actingAs($this->org->manager)->get('/admin/followup-settings')->assertForbidden();
    $this->actingAs($this->org->admin)->get('/admin/followup-settings')->assertOk();
    $this->actingAs($this->org->super)->get('/admin/followup-settings')->assertOk();
});

test('the module has no export or download route', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'follow-ups') || str_starts_with($r->uri(), 'notifications') || str_contains($r->uri(), 'followup'))
        ->map->uri();

    expect($routes)->not->toBeEmpty();
    foreach ($routes as $uri) {
        expect($uri)->not->toContain('export')->and($uri)->not->toContain('download')->and($uri)->not->toContain('csv');
    }
});
