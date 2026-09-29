<?php

use App\Models\Lead;
use App\Notifications\Meetings\MeetingNoteNotification;

beforeEach(function () {
    $this->org = salesOrg();
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create(['first_name' => 'Amit', 'last_name' => 'Desai']);
    $this->meeting = scheduleMeeting($this->lead, $this->org->rahul);
});

test('an admin note is saved and the salesperson is notified', function () {
    $this->actingAs($this->org->admin)
        ->post("/meetings/{$this->meeting->id}/notes", ['body' => 'Please confirm the site visit before noon.'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $note = $this->org->rahul->notifications()->where('type', MeetingNoteNotification::class)->sole();
    expect($note->data['event'])->toBe('meeting_note')
        ->and($note->data['message'])->toContain('Anita Admin')
        ->and($note->data['message'])->toContain('site visit')
        ->and($this->org->admin->notifications()->where('type', MeetingNoteNotification::class)->count())->toBe(0);

    $this->actingAs($this->org->rahul)->get("/meetings/{$this->meeting->id}")
        ->assertOk()
        ->assertSee('Please confirm the site visit before noon.', false);
});

test('a salesperson cannot add an admin meeting note', function () {
    $this->actingAs($this->org->rahul)
        ->post("/meetings/{$this->meeting->id}/notes", ['body' => 'I will handle it.'])
        ->assertForbidden();
});
