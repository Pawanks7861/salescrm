<?php

use App\Enums\AuditAction;
use App\Enums\CallRecordingStatus;
use App\Models\AuditLog;
use App\Models\CallRecording;
use App\Models\Lead;

beforeEach(function () {
    $this->org = salesOrg();
    telephonySetup([$this->org->rahul]);
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create();
    $this->call = finishCall($this, startCall($this->lead, $this->org->rahul), 'completed', 60, 'fake://recording/abc');
});

test('a completed call with a recording becomes available through the queued job', function () {
    $recording = $this->call->recording;

    expect($recording->status)->toBe(CallRecordingStatus::Available)
        ->and($recording->expires_at)->not->toBeNull()
        ->and(AuditLog::where('action', AuditAction::CallRecordingAvailable->value)->exists())->toBeTrue();
});

test('duplicate recording callbacks do not create a second recording', function () {
    telephonyCallback($this, ['call_id' => $this->call->provider_call_id, 'status' => 'completed', 'event_id' => 'again', 'talk_seconds' => 60, 'recording_url' => 'fake://recording/abc'])->assertOk();

    expect(CallRecording::count())->toBe(1);
});

test('an admin with listen permission can stream the recording and the listen is audited without the URL', function () {
    $response = $this->actingAs($this->org->admin)->get(route('calls.recording', $this->call));

    $response->assertOk()
        ->assertHeader('Content-Type', 'audio/wav')
        ->assertHeader('Accept-Ranges', 'bytes');
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('inline');
    expect(strlen($response->streamedContent()))->toBeGreaterThan(1000);

    $audit = AuditLog::where('action', AuditAction::CallRecordingListened->value)->sole();
    expect($audit->user_id)->toBe($this->org->admin->id)
        ->and(json_encode($audit->toArray()))->not->toContain('fake://recording');

    // Seeking (more Range requests) does not flood the audit log.
    $this->actingAs($this->org->admin)->get(route('calls.recording', $this->call), ['Range' => 'bytes=100-199'])->assertStatus(206);
    expect(AuditLog::where('action', AuditAction::CallRecordingListened->value)->count())->toBe(1);
});

test('HTTP range requests return partial content', function () {
    $response = $this->actingAs($this->org->admin)->get(route('calls.recording', $this->call), ['Range' => 'bytes=0-99']);

    $response->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-99/16044');
    expect(strlen($response->streamedContent()))->toBe(100);
});

test('sales executives cannot listen or download by default, even with the URL', function () {
    $this->actingAs($this->org->rahul)->get(route('calls.recording', $this->call))->assertForbidden();
    $this->actingAs($this->org->rahul)->get(route('calls.recording.download', $this->call))->assertForbidden();
});

test('managers and admins cannot download; download requires its own permission', function () {
    $this->actingAs($this->org->manager)->get(route('calls.recording.download', $this->call))->assertForbidden();
    $this->actingAs($this->org->admin)->get(route('calls.recording.download', $this->call))->assertForbidden();

    $response = $this->actingAs($this->org->super)->get(route('calls.recording.download', $this->call));
    $response->assertOk();
    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and(AuditLog::where('action', AuditAction::CallRecordingDownloaded->value)->exists())->toBeTrue();
});

test('listen permission alone never reaches recordings of leads the user does not own', function () {
    $this->actingAs($this->org->manager)->get(route('calls.recording', $this->call))->assertForbidden();
    $this->actingAs($this->org->otherManager)->get(route('calls.recording', $this->call))->assertForbidden();
});

test('the provider recording reference never reaches the browser', function () {
    $page = $this->actingAs($this->org->admin)->get(route('calls.show', $this->call));
    $page->assertOk();
    expect($page->getContent())->not->toContain('fake://recording')
        ->and($page->getContent())->toContain('calls\/'.$this->call->id.'\/recording');

    $lead = $this->actingAs($this->org->rahul)->get(route('leads.show', $this->lead));
    expect($lead->getContent())->not->toContain('fake://recording');
});

test('expired recordings are removed while the call record is kept', function () {
    $this->call->recording->forceFill(['expires_at' => now()->subDay()])->save();

    $this->artisan('telephony:prune')->assertSuccessful();

    $recording = $this->call->recording->fresh();
    expect($recording->status)->toBe(CallRecordingStatus::Expired)
        ->and($recording->provider_reference)->toBeNull()
        ->and($this->call->fresh())->not->toBeNull()
        ->and($this->call->fresh()->talk_duration_seconds)->toBe(60);

    $this->actingAs($this->org->admin)->get(route('calls.recording', $this->call))->assertNotFound();
});

test('recordings are not registered for calls that were not connected', function () {
    $busy = finishCall($this, startCall($this->lead, $this->org->rahul), 'busy', 0, 'fake://recording/zzz');

    expect($busy->recording)->toBeNull();
});
