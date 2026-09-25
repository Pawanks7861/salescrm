<?php

use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\LeadNoteHistory;

beforeEach(function () {
    $this->org = salesOrg();
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create();
});

test('owner adds a team note; activity and audit exclude content', function () {
    $this->actingAs($this->org->rahul)
        ->post("/leads/{$this->lead->id}/notes", ['note' => 'Secret pricing discussed', 'visibility' => 'team'])
        ->assertRedirect();

    $note = LeadNote::sole();
    expect($note->created_by)->toBe($this->org->rahul->id)->and($note->visibility->value)->toBe('team');

    $this->assertDatabaseHas('audit_logs', ['action' => 'LEAD_NOTE_CREATED', 'entity_id' => $note->id]);
    expect(AuditLog::where('action', 'LEAD_NOTE_CREATED')->first()->new_values_json)->not->toHaveKey('note');
    expect(Activity::where('type', 'note_added')->first()->description)->not->toContain('Secret');
});

test('editing a note keeps history', function () {
    $this->actingAs($this->org->rahul)->post("/leads/{$this->lead->id}/notes", ['note' => 'First', 'visibility' => 'team']);
    $note = LeadNote::sole();

    $this->actingAs($this->org->rahul)
        ->put("/leads/{$this->lead->id}/notes/{$note->id}", ['note' => 'Second', 'visibility' => 'private'])
        ->assertRedirect();

    $history = LeadNoteHistory::sole();
    expect($history->old_content)->toBe('First')->and($history->new_content)->toBe('Second')
        ->and($history->old_visibility)->toBe('team')->and($history->new_visibility)->toBe('private');
    $this->assertDatabaseHas('audit_logs', ['action' => 'LEAD_NOTE_UPDATED', 'entity_id' => $note->id]);
});

test('a user cannot edit or delete another users note without permission', function () {
    $note = new LeadNote(['note' => 'Manager note', 'visibility' => 'team']);
    $note->forceFill(['lead_id' => $this->lead->id, 'created_by' => $this->org->manager->id])->save();

    $this->actingAs($this->org->rahul)->put("/leads/{$this->lead->id}/notes/{$note->id}", ['note' => 'x', 'visibility' => 'team'])->assertForbidden();
    $this->actingAs($this->org->rahul)->delete("/leads/{$this->lead->id}/notes/{$note->id}")->assertForbidden();
});

test('note.delete needs lead access: the legacy team manager is refused, an admin soft deletes', function () {
    $this->actingAs($this->org->rahul)->post("/leads/{$this->lead->id}/notes", ['note' => 'To remove', 'visibility' => 'team']);
    $note = LeadNote::sole();

    $this->actingAs($this->org->manager)->delete("/leads/{$this->lead->id}/notes/{$note->id}")->assertForbidden();
    $this->actingAs($this->org->admin)->delete("/leads/{$this->lead->id}/notes/{$note->id}")->assertRedirect();

    expect(LeadNote::count())->toBe(0)->and(LeadNote::withTrashed()->count())->toBe(1);
    $this->assertDatabaseHas('audit_logs', ['action' => 'LEAD_NOTE_DELETED', 'entity_id' => $note->id]);
});

test('note route rejects a note that belongs to a different lead', function () {
    $other = Lead::factory()->assignedTo($this->org->rahul)->create();
    $this->actingAs($this->org->rahul)->post("/leads/{$other->id}/notes", ['note' => 'Other', 'visibility' => 'team']);
    $note = LeadNote::sole();

    $this->actingAs($this->org->rahul)->put("/leads/{$this->lead->id}/notes/{$note->id}", ['note' => 'x', 'visibility' => 'team'])->assertNotFound();
});

test('sales executive cannot choose management visibility', function () {
    $this->actingAs($this->org->rahul)
        ->post("/leads/{$this->lead->id}/notes", ['note' => 'x', 'visibility' => 'management'])
        ->assertSessionHasErrors('visibility');
});
