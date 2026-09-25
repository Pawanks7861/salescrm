<?php

use App\Models\Attachment;
use App\Models\Lead;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->org = salesOrg();
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create();
});

function uploadPdf($test, $user, $lead)
{
    return $test->actingAs($user)->post("/leads/{$lead->id}/attachments", [
        'file' => UploadedFile::fake()->create('quotation.pdf', 120, 'application/pdf'),
    ]);
}

test('owner uploads a file to the private disk with a random name', function () {
    uploadPdf($this, $this->org->rahul, $this->lead)->assertRedirect();

    $attachment = Attachment::sole();
    expect($attachment->original_name)->toBe('quotation.pdf')
        ->and($attachment->stored_name)->not->toContain('quotation')
        ->and($attachment->path)->toStartWith("leads/{$this->lead->id}/");
    Storage::disk('local')->assertExists($attachment->path);
    $this->assertDatabaseHas('audit_logs', ['action' => 'LEAD_ATTACHMENT_UPLOADED', 'entity_id' => $attachment->id]);
});

test('dangerous file types are rejected', function (string $name, string $mime) {
    $this->actingAs($this->org->rahul)->post("/leads/{$this->lead->id}/attachments", [
        'file' => UploadedFile::fake()->create($name, 10, $mime),
    ])->assertSessionHasErrors('file');

    expect(Attachment::count())->toBe(0);
})->with([
    ['shell.php', 'application/x-php'],
    ['page.html', 'text/html'],
    ['image.svg', 'image/svg+xml'],
    ['run.exe', 'application/x-msdownload'],
]);

test('files over the size limit are rejected', function () {
    $this->actingAs($this->org->rahul)->post("/leads/{$this->lead->id}/attachments", [
        'file' => UploadedFile::fake()->create('big.pdf', 11000, 'application/pdf'),
    ])->assertSessionHasErrors('file');
});

test('sales executive (no file.download) cannot download even on their own lead', function () {
    uploadPdf($this, $this->org->rahul, $this->lead);
    $attachment = Attachment::sole();

    $this->actingAs($this->org->rahul)->get("/leads/{$this->lead->id}/attachments/{$attachment->id}/download")->assertForbidden();
});

test('a manager with file.download still cannot download from a legacy team member lead', function () {
    uploadPdf($this, $this->org->rahul, $this->lead);
    $attachment = Attachment::sole();

    $this->actingAs($this->org->manager)->get("/leads/{$this->lead->id}/attachments/{$attachment->id}/download")->assertForbidden();
});

test('a manager granted lead.view_all with file.download can download; the download is audited', function () {
    uploadPdf($this, $this->org->rahul, $this->lead);
    $attachment = Attachment::sole();
    $this->org->manager = setPermission($this->org->manager, 'lead.view_all');

    $response = $this->actingAs($this->org->manager)->get("/leads/{$this->lead->id}/attachments/{$attachment->id}/download");
    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('quotation.pdf');
    expect($response->headers->get('cache-control'))->toContain('no-store');

    $this->assertDatabaseHas('audit_logs', ['action' => 'LEAD_ATTACHMENT_DOWNLOADED', 'entity_id' => $attachment->id, 'user_id' => $this->org->manager->id]);
});

test('attachment ids cannot be swapped between leads', function () {
    uploadPdf($this, $this->org->rahul, $this->lead);
    $attachment = Attachment::sole();
    $managerLead = Lead::factory()->assignedTo($this->org->manager)->create();

    $this->actingAs($this->org->manager)->get("/leads/{$managerLead->id}/attachments/{$attachment->id}/download")->assertNotFound();
});

test('storage paths are never sent to the browser', function () {
    uploadPdf($this, $this->org->rahul, $this->lead);

    $content = $this->actingAs($this->org->rahul)->get("/leads/{$this->lead->id}")->getContent();
    expect($content)->not->toContain(Attachment::sole()->stored_name);
});

test('uploader can delete; others without lead.delete cannot', function () {
    $this->org->manager = setPermission($this->org->manager, 'lead.view_all');
    uploadPdf($this, $this->org->manager, $this->lead);
    $attachment = Attachment::sole();

    $this->actingAs($this->org->rahul)->delete("/leads/{$this->lead->id}/attachments/{$attachment->id}")->assertForbidden();
    $this->actingAs($this->org->manager)->delete("/leads/{$this->lead->id}/attachments/{$attachment->id}")->assertRedirect();

    expect(Attachment::count())->toBe(0);
    $this->assertDatabaseHas('audit_logs', ['action' => 'LEAD_ATTACHMENT_DELETED', 'entity_id' => $attachment->id]);
});
