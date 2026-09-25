<?php

/*
| RELEASE-BLOCKING: Rahul (sales executive) must not be able to read or change
| Priya's lead through any endpoint, nor discover it through search, duplicate
| lookup, pipeline or attachment URLs. Every attempt must fail or omit the record.
*/

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\LostReason;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->org = salesOrg();

    $this->priyaLead = Lead::factory()->assignedTo($this->org->priya)->create([
        'first_name' => 'Confidential', 'last_name' => 'Client', 'full_name' => 'Confidential Client',
        'lead_number' => 'LD-2026-004242', 'phone' => '9898012345', 'normalized_phone' => '919898012345',
        'email' => 'secret.client@example.com', 'company_name' => 'Hidden Corp',
    ]);

    $note = new LeadNote(['note' => 'Priya private strategy', 'visibility' => 'team']);
    $note->forceFill(['lead_id' => $this->priyaLead->id, 'created_by' => $this->org->priya->id])->save();
    $this->note = $note;

    $this->actingAs($this->org->priya)->post("/leads/{$this->priyaLead->id}/attachments", [
        'file' => UploadedFile::fake()->create('contract.pdf', 50, 'application/pdf'),
    ]);
    $this->attachment = Attachment::sole();

    $this->rahul = $this->org->rahul;
    $this->snapshot = fn () => $this->priyaLead->fresh()->only('assigned_to', 'team_id', 'status_id', 'priority', 'first_name', 'deleted_at');
    $this->before = ($this->snapshot)();
});

afterEach(function () {
    expect(($this->snapshot)())->toEqual($this->before);
    expect(LeadNote::where('lead_id', $this->priyaLead->id)->count())->toBe(1);
});

test('GET lead details is forbidden', function () {
    $this->actingAs($this->rahul)->get("/leads/{$this->priyaLead->id}")->assertForbidden();
});

test('edit form is forbidden', function () {
    $this->actingAs($this->rahul)->get("/leads/{$this->priyaLead->id}/edit")->assertForbidden();
});

test('update is forbidden', function () {
    $this->actingAs($this->rahul)->put("/leads/{$this->priyaLead->id}", leadPayload(['first_name' => 'Hacked']))->assertForbidden();
});

test('archive is forbidden', function () {
    $this->actingAs($this->rahul)->delete("/leads/{$this->priyaLead->id}")->assertForbidden();
});

test('adding a note is forbidden', function () {
    $this->actingAs($this->rahul)->post("/leads/{$this->priyaLead->id}/notes", ['note' => 'x', 'visibility' => 'team'])->assertForbidden();
});

test('editing, deleting or reading history of her note is forbidden', function () {
    $this->actingAs($this->rahul)->put("/leads/{$this->priyaLead->id}/notes/{$this->note->id}", ['note' => 'x', 'visibility' => 'team'])->assertForbidden();
    $this->actingAs($this->rahul)->delete("/leads/{$this->priyaLead->id}/notes/{$this->note->id}")->assertForbidden();
    $this->actingAs($this->rahul)->getJson("/leads/{$this->priyaLead->id}/notes/{$this->note->id}/history")->assertForbidden();
});

test('downloading, uploading or deleting attachments is forbidden', function () {
    $this->actingAs($this->rahul)->get("/leads/{$this->priyaLead->id}/attachments/{$this->attachment->id}/download")->assertForbidden();
    $this->actingAs($this->rahul)->post("/leads/{$this->priyaLead->id}/attachments", ['file' => UploadedFile::fake()->create('x.pdf', 5, 'application/pdf')])->assertForbidden();
    $this->actingAs($this->rahul)->delete("/leads/{$this->priyaLead->id}/attachments/{$this->attachment->id}")->assertForbidden();
    expect(Attachment::count())->toBe(1);
});

test('changing status (including lost) is forbidden', function () {
    $this->actingAs($this->rahul)->post("/leads/{$this->priyaLead->id}/status", ['status_id' => leadStatusId('contacted')])->assertForbidden();
    $this->actingAs($this->rahul)->post("/leads/{$this->priyaLead->id}/status", ['status_id' => leadStatusId('lost'), 'lost_reason_id' => LostReason::value('id')])->assertForbidden();
});

test('changing priority is forbidden', function () {
    $this->actingAs($this->rahul)->post("/leads/{$this->priyaLead->id}/priority", ['priority' => 'urgent'])->assertForbidden();
});

test('reassigning to himself is forbidden', function () {
    $this->actingAs($this->rahul)->post("/leads/{$this->priyaLead->id}/assign", ['assigned_to' => $this->rahul->id])->assertForbidden();
});

test('activity timeline is forbidden', function () {
    $this->actingAs($this->rahul)->getJson("/leads/{$this->priyaLead->id}/activities")->assertForbidden();
});

test('list search by lead number omits the record', function () {
    $response = $this->actingAs($this->rahul)->get('/leads?search=LD-2026-004242')->assertOk();
    expect($response->inertiaProps('leads.data'))->toBe([]);
    expect($response->getContent())->not->toContain('Confidential');
});

test('list search by phone (any format) omits the record', function () {
    foreach (['9898012345', '+91 98980 12345', '012345'] as $q) {
        expect($this->actingAs($this->rahul)->get('/leads?search='.urlencode($q))->inertiaProps('leads.data'))->toBe([]);
    }
});

test('list search by name, email and company omits the record', function () {
    foreach (['Confidential', 'secret.client', 'Hidden'] as $q) {
        expect($this->actingAs($this->rahul)->get('/leads?search='.urlencode($q))->inertiaProps('leads.data'))->toBe([]);
    }
});

test('global topbar search omits the record', function () {
    foreach (['LD-2026-004242', '9898012345', 'Confidential'] as $q) {
        $this->actingAs($this->rahul)->getJson('/search/leads?q='.urlencode($q))->assertOk()->assertExactJson(['results' => []]);
    }
});

test('duplicate lookup by phone or email reveals nothing', function () {
    $this->actingAs($this->rahul)->postJson('/leads/duplicate-check', ['phone' => '9898012345'])->assertExactJson(['matches' => []]);
    $this->actingAs($this->rahul)->postJson('/leads/duplicate-check', ['email' => 'secret.client@example.com'])->assertExactJson(['matches' => []]);
});

test('pipeline and load-more omit the record', function () {
    $columns = $this->actingAs($this->rahul)->get('/leads/pipeline')->inertiaProps('columns');
    expect(collect($columns)->sum('count'))->toBe(0);

    $this->actingAs($this->rahul)->getJson('/leads/pipeline/'.leadStatusId('new').'/more?offset=0')->assertExactJson(['cards' => []]);
});

test('filter parameters cannot be used to reach her leads', function () {
    expect($this->actingAs($this->rahul)->get("/leads?assignee={$this->org->priya->id}")->inertiaProps('leads.data'))->toBe([]);
    expect($this->actingAs($this->rahul)->get("/leads?team={$this->org->team->id}")->inertiaProps('leads.data'))->toBe([]);
    expect($this->actingAs($this->rahul)->get('/leads?archived=only')->inertiaProps('leads.data'))->toBe([]);
});

test('every refusal is written to the audit log', function () {
    $this->actingAs($this->rahul)->get("/leads/{$this->priyaLead->id}");
    $this->actingAs($this->rahul)->get("/leads/{$this->priyaLead->id}/attachments/{$this->attachment->id}/download");

    expect(AuditLog::where('action', 'ACCESS_DENIED')->where('user_id', $this->rahul->id)->count())->toBeGreaterThanOrEqual(2);
});
