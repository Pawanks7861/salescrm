<?php

use App\Models\Attachment;
use App\Models\Lead;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/*
| Phase 8 §41–42: attachments and exports live on the private
| disk and are only reachable through authorising controllers.
*/

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->org = salesOrg();
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create();

    $this->actingAs($this->org->rahul)->post("/leads/{$this->lead->id}/attachments", [
        'file' => UploadedFile::fake()->create('contract.pdf', 50, 'application/pdf'),
    ]);
    $this->attachment = Attachment::sole();
    auth()->logout();
});

test('the private disk is not served by any public route', function () {
    expect(config('filesystems.disks.local.serve'))->toBeFalse()
        ->and(Route::has('storage.local'))->toBeFalse()
        ->and(Route::has('storage.local.upload'))->toBeFalse();

    $this->actingAs($this->org->super)->get('/storage/'.$this->attachment->path)->assertNotFound();
});

test('uploads never land on the public disk and paths are never exposed', function () {
    expect(Storage::disk('public')->allFiles())->toBe([])
        ->and($this->attachment->stored_name)->not->toContain('contract');

    $html = $this->actingAs($this->org->admin)->get("/leads/{$this->lead->id}")->getContent();
    expect($html)->not->toContain($this->attachment->path);
});

test('attachment downloads require login, permission and lead visibility', function () {
    $url = "/leads/{$this->lead->id}/attachments/{$this->attachment->id}/download";

    $this->get($url)->assertRedirect('/login');
    $this->actingAs($this->org->rahul)->get($url)->assertForbidden();
    $this->actingAs($this->org->otherManager)->get($url)->assertForbidden();
    $this->actingAs($this->org->manager)->get($url)->assertForbidden();
    $viewer = setPermission($this->org->manager, 'lead.view_all');
    $this->actingAs($viewer)->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('branding files are only served through the branding controller', function () {
    $this->get('/storage/branding/logo/anything.png')->assertNotFound();
    expect(config('filesystems.disks.branding'))->not->toHaveKey('url');
});
