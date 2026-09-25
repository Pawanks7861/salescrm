<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Permission;
use App\Models\User;
use App\Services\PermissionRegistrar;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->org = salesOrg();
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create();
});

test('owner can view and edit their lead', function () {
    $this->actingAs($this->org->rahul)->get("/leads/{$this->lead->id}")->assertOk();
    $this->actingAs($this->org->rahul)->get("/leads/{$this->lead->id}/edit")->assertOk();
});

test('manager outside the team cannot view the lead', function () {
    $this->actingAs($this->org->otherManager)->get("/leads/{$this->lead->id}")->assertForbidden();
});

test('unauthorised access is audited as ACCESS_DENIED', function () {
    $this->actingAs($this->org->priya)->get("/leads/{$this->lead->id}")->assertForbidden();

    expect(AuditLog::where('action', AuditAction::AccessDenied->value)->where('user_id', $this->org->priya->id)->exists())->toBeTrue();
});

test('sales executive cannot archive, restore or reassign even their own lead', function () {
    $this->actingAs($this->org->rahul)->delete("/leads/{$this->lead->id}")->assertForbidden();
    $this->actingAs($this->org->rahul)->post("/leads/{$this->lead->id}/assign", ['assigned_to' => $this->org->priya->id])->assertForbidden();

    $this->lead->delete();
    $this->actingAs($this->org->rahul)->post("/leads/{$this->lead->id}/restore")->assertForbidden();
});

test('a user with lead.view but lead.edit denied cannot update', function () {
    $deny = Permission::where('name', 'lead.edit')->first();
    $this->org->rahul->permissionOverrides()->attach($deny->id, ['type' => 'deny']);
    app(PermissionRegistrar::class)->flushUser($this->org->rahul);

    $this->actingAs($this->org->rahul)->get("/leads/{$this->lead->id}")->assertOk();
    $this->actingAs($this->org->rahul)->put("/leads/{$this->lead->id}", leadPayload())->assertForbidden();
});

test('super admin can view and update any lead', function () {
    $this->actingAs($this->org->super)->get("/leads/{$this->lead->id}")->assertOk();
    $this->actingAs($this->org->super)->put("/leads/{$this->lead->id}", leadPayload(['first_name' => 'Changed']))->assertRedirect();

    expect($this->lead->fresh()->first_name)->toBe('Changed');
});

test('update ignores source change without lead.edit_source', function () {
    $original = $this->lead->source_id;
    $other = LeadSource::where('id', '!=', $original)->value('id');

    $this->actingAs($this->org->rahul)->put("/leads/{$this->lead->id}", leadPayload(['source_id' => $other]))->assertRedirect();

    expect($this->lead->fresh()->source_id)->toBe($original);
});

test('no lead export route exists', function () {
    expect(collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'lead') && str_contains($r->uri(), 'export')))->toBeEmpty();
    $this->actingAs($this->org->rahul)->get('/leads/export')->assertNotFound();
});

test('inactive users are blocked from lead pages', function () {
    $inactive = User::factory()->salesExecutive()->inactive()->create();

    $this->actingAs($inactive)->get('/leads')->assertRedirect();
});
