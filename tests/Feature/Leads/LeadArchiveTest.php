<?php

use App\Models\Lead;

beforeEach(function () {
    $this->org = salesOrg();
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create();
});

test('admin archives a lead (soft delete) and it disappears from lists', function () {
    $this->actingAs($this->org->admin)->delete("/leads/{$this->lead->id}")->assertRedirect(route('leads.index'));

    expect(Lead::find($this->lead->id))->toBeNull()->and(Lead::withTrashed()->find($this->lead->id))->not->toBeNull();
    $this->assertDatabaseHas('audit_logs', ['action' => 'LEAD_ARCHIVED', 'entity_id' => $this->lead->id]);

    $ids = collect($this->actingAs($this->org->admin)->get('/leads')->inertiaProps('leads.data'))->pluck('id');
    expect($ids)->not->toContain($this->lead->id);
});

test('archived filter is available only with delete/restore permission', function () {
    $this->lead->delete();

    $admin = collect($this->actingAs($this->org->admin)->get('/leads?archived=only')->inertiaProps('leads.data'))->pluck('id');
    expect($admin)->toContain($this->lead->id);

    $manager = collect($this->actingAs($this->org->manager)->get('/leads?archived=only')->inertiaProps('leads.data'))->pluck('id');
    expect($manager)->not->toContain($this->lead->id);
});

test('owner cannot view an archived lead', function () {
    $this->lead->delete();

    $this->actingAs($this->org->rahul)->get("/leads/{$this->lead->id}")->assertForbidden();
});

test('restore requires lead.restore (super admin); admin lacks it by default', function () {
    $this->lead->delete();

    $this->actingAs($this->org->admin)->post("/leads/{$this->lead->id}/restore")->assertForbidden();
    $this->actingAs($this->org->super)->post("/leads/{$this->lead->id}/restore")->assertRedirect();

    expect($this->lead->fresh()->trashed())->toBeFalse();
    $this->assertDatabaseHas('audit_logs', ['action' => 'LEAD_RESTORED', 'entity_id' => $this->lead->id]);
});

test('archived leads are read-only', function () {
    $this->lead->delete();

    $this->actingAs($this->org->super)->put("/leads/{$this->lead->id}", leadPayload())->assertNotFound();
    $this->actingAs($this->org->super)->post("/leads/{$this->lead->id}/status", ['status_id' => leadStatusId('contacted')])->assertNotFound();
});
