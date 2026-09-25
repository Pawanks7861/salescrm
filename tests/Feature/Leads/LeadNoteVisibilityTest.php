<?php

use App\Models\Lead;
use App\Models\LeadNote;

beforeEach(function () {
    $this->org = salesOrg();
    // Note tiers are tested with an explicit company-wide lead grant; team membership gives no access.
    $this->org->manager = setPermission($this->org->manager, 'lead.view_all');
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create();

    $make = function (string $visibility, $author) {
        $note = new LeadNote(['note' => "{$visibility} note by {$author->name}", 'visibility' => $visibility]);
        $note->forceFill(['lead_id' => $this->lead->id, 'created_by' => $author->id])->save();

        return $note;
    };

    $this->rahulPrivate = $make('private', $this->org->rahul);
    $this->managerPrivate = $make('private', $this->org->manager);
    $this->managerMgmt = $make('management', $this->org->manager);
    $this->managerTeam = $make('team', $this->org->manager);
});

function noteIds($test, $user): array
{
    return collect($test->actingAs($user)->get("/leads/{$test->lead->id}")->assertOk()->inertiaProps('notes'))->pluck('id')->sort()->values()->all();
}

test('owner sees team notes and own private notes only', function () {
    expect(noteIds($this, $this->org->rahul))->toBe(collect([$this->rahulPrivate->id, $this->managerTeam->id])->sort()->values()->all());
});

test('manager sees management notes and own private notes but not the executives private note', function () {
    $ids = noteIds($this, $this->org->manager);

    expect($ids)->toContain($this->managerPrivate->id, $this->managerMgmt->id, $this->managerTeam->id)
        ->not->toContain($this->rahulPrivate->id);
});

test('admin with note.view_private sees everything', function () {
    expect(noteIds($this, $this->org->admin))->toHaveCount(4);
});

test('history endpoint enforces note visibility', function () {
    $this->actingAs($this->org->rahul)->getJson("/leads/{$this->lead->id}/notes/{$this->managerMgmt->id}/history")->assertForbidden();
    $this->actingAs($this->org->manager)->getJson("/leads/{$this->lead->id}/notes/{$this->managerMgmt->id}/history")->assertOk();
});

test('timeline hides activity about notes the viewer cannot read', function () {
    $this->actingAs($this->org->manager)->post("/leads/{$this->lead->id}/notes", ['note' => 'Pricing strategy', 'visibility' => 'management'])->assertRedirect();
    $this->actingAs($this->org->rahul)->post("/leads/{$this->lead->id}/notes", ['note' => 'Called', 'visibility' => 'team'])->assertRedirect();

    $types = fn ($user, string $prop) => collect($prop === 'show'
        ? $this->actingAs($user)->get("/leads/{$this->lead->id}")->inertiaProps('activities.data')
        : $this->actingAs($user)->getJson("/leads/{$this->lead->id}/activities")->json('data'))
        ->pluck('description')->all();

    foreach (['show', 'json'] as $source) {
        expect($types($this->org->rahul, $source))->toContain('Added a team note')->not->toContain('Added a management note');
        expect($types($this->org->manager, $source))->toContain('Added a management note', 'Added a team note');
    }
});

test('users who cannot see the lead cannot see any of its notes', function () {
    $this->actingAs($this->org->priya)->get("/leads/{$this->lead->id}")->assertForbidden();
    $this->actingAs($this->org->priya)->getJson("/leads/{$this->lead->id}/notes/{$this->managerTeam->id}/history")->assertForbidden();
    $this->actingAs($this->org->otherManager)->getJson("/leads/{$this->lead->id}/notes/{$this->managerTeam->id}/history")->assertForbidden();
});
