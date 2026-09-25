<?php

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Phase 3 pre-flight: confirms the Phase 1/2 permission defaults that later
 * phases rely on. These tests document behaviour; they do not change it.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->org = salesOrg();
    $this->rahulLead = Lead::factory()->assignedTo($this->org->rahul)->create();
    $this->outsiderLead = Lead::factory()->assignedTo($this->org->outsider)->create();
});

test('super admin can use every Phase 2 lead function on any lead', function () {
    $super = $this->org->super;
    $lead = $this->outsiderLead;

    $this->actingAs($super)->get('/leads')->assertOk();
    expect(collect($this->actingAs($super)->get('/leads')->inertiaProps('leads.data'))->pluck('id'))
        ->toContain($this->rahulLead->id, $this->outsiderLead->id);

    $this->actingAs($super)->get("/leads/{$lead->id}")->assertOk();
    $this->actingAs($super)->get("/leads/{$lead->id}/edit")->assertOk();
    $this->actingAs($super)->get('/leads/pipeline')->assertOk();
    $this->actingAs($super)->post("/leads/{$lead->id}/status", ['status_id' => leadStatusId('contacted')])->assertRedirect();
    $this->actingAs($super)->post("/leads/{$lead->id}/assign", ['assigned_to' => $this->org->rahul->id])->assertSessionHasNoErrors();
    expect($lead->fresh()->assigned_to)->toBe($this->org->rahul->id);

    $this->actingAs($super)->post("/leads/{$lead->id}/notes", ['note' => 'Checked', 'visibility' => 'management'])->assertRedirect();
    $this->actingAs($super)->post("/leads/{$lead->id}/attachments", ['file' => UploadedFile::fake()->create('q.pdf', 10, 'application/pdf')])->assertRedirect();
    $this->actingAs($super)->get("/leads/{$lead->id}/attachments/".Attachment::sole()->id.'/download')->assertOk();

    $this->actingAs($super)->delete("/leads/{$lead->id}")->assertRedirect();
    $this->actingAs($super)->post("/leads/{$lead->id}/restore")->assertRedirect();
    expect($lead->fresh()->trashed())->toBeFalse();

    foreach (['/admin/lead-settings', '/admin/custom-fields', '/admin/assignment-rules'] as $url) {
        $this->actingAs($super)->get($url)->assertOk();
    }
});

test('super admin bypass still respects system-protection rules', function () {
    $super = $this->org->super;
    $superRole = Role::where('slug', 'super_admin')->first();
    $salesRole = Role::where('slug', 'sales_executive')->first();

    $this->actingAs($super)->delete("/admin/roles/{$superRole->id}")->assertForbidden();
    $this->actingAs($super)->delete("/admin/roles/{$salesRole->id}")->assertForbidden();
    $this->actingAs($super)->post("/admin/users/{$super->id}/toggle-active")->assertForbidden();
    expect($super->fresh()->is_active)->toBeTrue();

    $log = AuditLog::query()->latest('id')->firstOrFail();
    $this->actingAs($super)->delete("/admin/audit-logs/{$log->id}")->assertStatus(405);
});

test('sales executive cannot download attachments without file.download (default)', function () {
    expect($this->org->rahul->hasPermission(Permissions::FILE_DOWNLOAD))->toBeFalse();

    $this->actingAs($this->org->rahul)->post("/leads/{$this->rahulLead->id}/attachments", ['file' => UploadedFile::fake()->create('q.pdf', 10, 'application/pdf')])->assertRedirect();
    $this->actingAs($this->org->rahul)->get("/leads/{$this->rahulLead->id}/attachments/".Attachment::sole()->id.'/download')->assertForbidden();
});

test('admin cannot restore archived leads without lead.restore (default)', function () {
    expect($this->org->admin->hasPermission(Permissions::LEAD_RESTORE))->toBeFalse();

    $this->rahulLead->delete();
    $this->actingAs($this->org->admin)->post("/leads/{$this->rahulLead->id}/restore")->assertForbidden();
    expect($this->rahulLead->fresh()->trashed())->toBeTrue();
});
