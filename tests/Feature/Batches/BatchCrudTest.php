<?php

use App\Enums\BatchStatus;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Lead;
use App\Support\Navigation;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->org = salesOrg();
});

test('the batch permissions are in the catalogue with role defaults', function () {
    $catalogue = array_keys(Permissions::all());
    foreach ([Permissions::BATCH_VIEW, Permissions::BATCH_CREATE, Permissions::BATCH_EDIT, Permissions::BATCH_DELETE, Permissions::BATCH_MANAGE_LEADS] as $permission) {
        expect($catalogue)->toContain($permission)
            ->and(Permissions::all()[$permission]['module'])->toBe('Batches');
        expect($this->org->admin->hasPermission($permission))->toBeTrue();
    }

    expect($this->org->rahul->hasPermission(Permissions::BATCH_VIEW))->toBeTrue()
        ->and($this->org->rahul->hasPermission(Permissions::BATCH_MANAGE_LEADS))->toBeTrue()
        ->and($this->org->rahul->hasPermission(Permissions::BATCH_CREATE))->toBeFalse()
        ->and($this->org->rahul->hasPermission(Permissions::BATCH_DELETE))->toBeFalse()
        ->and($this->org->manager->hasPermission(Permissions::BATCH_CREATE))->toBeTrue()
        ->and($this->org->manager->hasPermission(Permissions::BATCH_EDIT))->toBeTrue()
        ->and($this->org->manager->hasPermission(Permissions::BATCH_DELETE))->toBeFalse();
});

test('an authorised user creates a batch with a unique BAT-YYYY number and is redirected to it', function () {
    $response = $this->actingAs($this->org->manager)->post('/batches', ['name' => 'October Campaign', 'description' => 'Diwali push']);

    $batch = Batch::sole();
    $response->assertRedirect("/batches/{$batch->id}");
    expect($batch->batch_number)->toMatch('/^BAT-'.now()->format('Y').'-\d{6}$/')
        ->and($batch->status)->toBe(BatchStatus::Active)
        ->and($batch->created_by)->toBe($this->org->manager->id);

    $this->actingAs($this->org->manager)->post('/batches', ['name' => 'November Campaign']);
    expect(Batch::pluck('batch_number')->unique())->toHaveCount(2);

    $log = AuditLog::where('action', 'BATCH_CREATED')->where('entity_id', $batch->id)->sole();
    expect($log->user_id)->toBe($this->org->manager->id);
});

test('batch name is required', function () {
    $this->actingAs($this->org->manager)->post('/batches', ['name' => ''])->assertSessionHasErrors('name');
    expect(Batch::count())->toBe(0);
});

test('users without batch.create or batch.edit get 403', function () {
    $batch = Batch::factory()->create(['name' => 'Locked']);

    $this->actingAs($this->org->rahul)->get('/batches/create')->assertForbidden();
    $this->actingAs($this->org->rahul)->post('/batches', ['name' => 'Nope'])->assertForbidden();
    $this->actingAs($this->org->rahul)->get("/batches/{$batch->id}/edit")->assertForbidden();
    $this->actingAs($this->org->rahul)->put("/batches/{$batch->id}", ['name' => 'Renamed'])->assertForbidden();
    $this->actingAs($this->org->manager)->post("/batches/{$batch->id}/archive")->assertForbidden();
    $this->actingAs($this->org->manager)->delete("/batches/{$batch->id}")->assertForbidden();

    expect($batch->fresh()->name)->toBe('Locked')->and(Batch::count())->toBe(1);
});

test('users without batch.view cannot reach any batch page', function () {
    $user = setPermission($this->org->rahul, Permissions::BATCH_VIEW, 'deny');
    $batch = Batch::factory()->create();

    $this->actingAs($user)->get('/batches')->assertForbidden();
    $this->actingAs($user)->get("/batches/{$batch->id}")->assertForbidden();
    $this->actingAs($user)->post("/batches/{$batch->id}/leads", ['lead_ids' => [1]])->assertForbidden();
});

test('an authorised user updates a batch and the change is audited', function () {
    $batch = Batch::factory()->create(['name' => 'Old name']);

    $this->actingAs($this->org->manager)->put("/batches/{$batch->id}", ['name' => 'New name', 'status' => 'inactive'])
        ->assertRedirect("/batches/{$batch->id}");

    expect($batch->fresh())->name->toBe('New name')->status->toBe(BatchStatus::Inactive);
    $log = AuditLog::where('action', 'BATCH_UPDATED')->where('entity_id', $batch->id)->sole();
    expect($log->new_values_json)->toHaveKey('name');
});

test('archive and restore are audited; archived batches stay viewable', function () {
    $batch = Batch::factory()->create();

    $this->actingAs($this->org->admin)->post("/batches/{$batch->id}/archive")->assertRedirect();
    expect($batch->fresh()->status)->toBe(BatchStatus::Archived);
    $this->assertDatabaseHas('audit_logs', ['action' => 'BATCH_ARCHIVED', 'entity_id' => $batch->id]);

    $this->actingAs($this->org->rahul)->get("/batches/{$batch->id}")->assertOk();

    $this->actingAs($this->org->admin)->post("/batches/{$batch->id}/restore")->assertRedirect();
    expect($batch->fresh()->status)->toBe(BatchStatus::Active);
});

test('editing an archived batch cannot un-archive it through the status field', function () {
    $batch = Batch::factory()->archived()->create();

    $this->actingAs($this->org->admin)->put("/batches/{$batch->id}", ['name' => 'Renamed', 'status' => 'active']);

    expect($batch->fresh())->name->toBe('Renamed')->status->toBe(BatchStatus::Archived);
});

test('deleting a batch removes its memberships but never its leads', function () {
    $batch = Batch::factory()->create();
    $other = Batch::factory()->create();
    $leads = Lead::factory()->count(3)->assignedTo($this->org->rahul)->create();
    $batch->leads()->attach($leads->pluck('id'));
    $other->leads()->attach($leads->first()->id);

    $this->actingAs($this->org->admin)->delete("/batches/{$batch->id}")->assertRedirect('/batches');

    expect(Batch::find($batch->id))->toBeNull()
        ->and(DB::table('batch_leads')->where('batch_id', $batch->id)->count())->toBe(0)
        ->and(DB::table('batch_leads')->where('batch_id', $other->id)->count())->toBe(1)
        ->and(Lead::whereIn('id', $leads->pluck('id'))->count())->toBe(3);

    $log = AuditLog::where('action', 'BATCH_DELETED')->where('entity_id', $batch->id)->sole();
    expect($log->new_values_json)->toBe(['batch_id' => $batch->id, 'lead_count' => 3]);

    $this->actingAs($this->org->admin)->get("/batches/{$batch->id}")->assertNotFound();
});

test('the batch list shows visible lead counts and archived batches last', function () {
    $archived = Batch::factory()->archived()->create(['name' => 'Aaa archived']);
    $active = Batch::factory()->create(['name' => 'Zzz active']);
    $active->leads()->attach([
        Lead::factory()->assignedTo($this->org->rahul)->create()->id,
        Lead::factory()->assignedTo($this->org->priya)->create()->id,
    ]);

    $rows = collect($this->actingAs($this->org->rahul)->get('/batches')->assertOk()->inertiaProps('batches.data'));

    expect($rows->pluck('id')->all())->toBe([$active->id, $archived->id])
        ->and($rows->firstWhere('id', $active->id)['leads_count'])->toBe(1);

    $adminRows = collect($this->actingAs($this->org->admin)->get('/batches')->inertiaProps('batches.data'));
    expect($adminRows->firstWhere('id', $active->id)['leads_count'])->toBe(2);
});

test('Batches appears in navigation only for batch.view holders', function () {
    $labels = fn ($user) => collect(Navigation::for($user))->flatMap(fn ($section) => collect($section['items'] ?? [])->pluck('label'))->all();

    expect($labels($this->org->rahul))->toContain('Batches');

    $denied = setPermission($this->org->priya, Permissions::BATCH_VIEW, 'deny');
    expect($labels($denied))->not->toContain('Batches');
});
