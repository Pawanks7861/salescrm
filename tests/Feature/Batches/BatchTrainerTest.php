<?php

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\Batches\BatchTrainerNotification;
use App\Services\Batches\BatchService;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->org = salesOrg();
    $this->amit = User::factory()->trainer()->create(['name' => 'Amit Shah', 'email' => 'amit.trainer@example.com', 'employee_code' => 'TR-101']);
    $this->neha = User::factory()->trainer()->create(['name' => 'Neha Joshi']);
    $this->kiran = User::factory()->trainer()->create(['name' => 'Kiran Rao']);
    $this->batch = Batch::factory()->create(['name' => 'October Batch']);
});

function batchTrainerIds(Batch $batch): array
{
    return DB::table('batch_trainers')->where('batch_id', $batch->id)->orderBy('trainer_id')->pluck('trainer_id')->map(fn ($id) => (int) $id)->all();
}

function batchTrainerNotifications(User $user): \Illuminate\Support\Collection
{
    return $user->fresh()->notifications()->get()->filter(fn ($n) => ($n->data['category'] ?? null) === 'batch')->values();
}

/*
| Roles and permissions
*/

test('trainers are users with the Trainer role and batch.manage_trainers follows the role defaults', function () {
    expect(Permissions::all()[Permissions::BATCH_MANAGE_TRAINERS]['module'])->toBe('Batches')
        ->and($this->org->admin->hasPermission(Permissions::BATCH_MANAGE_TRAINERS))->toBeTrue()
        ->and($this->org->manager->hasPermission(Permissions::BATCH_MANAGE_TRAINERS))->toBeTrue()
        ->and($this->org->rahul->hasPermission(Permissions::BATCH_MANAGE_TRAINERS))->toBeFalse()
        ->and($this->amit->isTrainer())->toBeTrue()
        ->and($this->amit->hasPermission(Permissions::BATCH_VIEW))->toBeTrue()
        ->and($this->amit->hasPermission(Permissions::BATCH_MANAGE_TRAINERS))->toBeFalse()
        ->and($this->amit->hasPermission(Permissions::LEAD_VIEW))->toBeFalse()
        ->and($this->amit->hasPermission(Permissions::LEAD_VIEW_ALL))->toBeFalse();

    expect(User::query()->eligibleTrainer()->orderBy('id')->pluck('id')->all())
        ->toBe([$this->amit->id, $this->neha->id, $this->kiran->id]);
});

/*
| Assignment
*/

test('an eligible trainer is assigned to a batch and audited with ids only', function () {
    $this->actingAs($this->org->manager)->post("/batches/{$this->batch->id}/trainers", ['trainer_ids' => [$this->amit->id]])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(batchTrainerIds($this->batch))->toBe([$this->amit->id]);
    $row = DB::table('batch_trainers')->where('batch_id', $this->batch->id)->sole();
    expect((int) $row->assigned_by)->toBe($this->org->manager->id)->and($row->created_at)->not->toBeNull();

    $log = AuditLog::where('action', 'BATCH_TRAINER_ADDED')->sole();
    expect($log->new_values_json)->toBe(['batch_id' => $this->batch->id, 'trainer_id' => $this->amit->id])
        ->and($log->user_id)->toBe($this->org->manager->id);
});

test('multiple trainers are assigned in one request and audited as one bulk event', function () {
    $this->actingAs($this->org->manager)->post("/batches/{$this->batch->id}/trainers", ['trainer_ids' => [$this->amit->id, $this->neha->id]])
        ->assertSessionHasNoErrors();

    expect(batchTrainerIds($this->batch))->toBe([$this->amit->id, $this->neha->id]);
    expect(AuditLog::where('action', 'BATCH_TRAINERS_BULK_ADDED')->sole()->new_values_json)->toBe(['batch_id' => $this->batch->id, 'trainer_count' => 2]);
});

test('the same trainer is never assigned twice and repeating the request is harmless', function () {
    $this->actingAs($this->org->manager)->post("/batches/{$this->batch->id}/trainers", ['trainer_ids' => [$this->amit->id]]);
    $this->actingAs($this->org->manager)->post("/batches/{$this->batch->id}/trainers", ['trainer_ids' => [$this->amit->id, $this->neha->id]])
        ->assertSessionHasNoErrors()->assertSessionHas('success', '1 trainer added to October Batch. 1 already assigned.');

    expect(batchTrainerIds($this->batch))->toBe([$this->amit->id, $this->neha->id])
        ->and(AuditLog::where('action', 'like', 'BATCH_TRAINER%')->count())->toBe(2);

    $this->actingAs($this->org->manager)->post("/batches/{$this->batch->id}/trainers", ['trainer_ids' => [$this->kiran->id, $this->kiran->id]])
        ->assertSessionHasErrors('trainer_ids.0');
    expect(batchTrainerIds($this->batch))->not->toContain($this->kiran->id);
});

test('the database rejects a duplicate assignment row', function () {
    $row = ['batch_id' => $this->batch->id, 'trainer_id' => $this->amit->id, 'created_at' => now()];
    DB::table('batch_trainers')->insert($row);

    expect(fn () => DB::table('batch_trainers')->insert($row))->toThrow(Illuminate\Database\QueryException::class);
});

test('a trainer can belong to several batches', function () {
    $other = Batch::factory()->create(['name' => 'November Batch']);
    $service = app(BatchService::class);

    $service->assignTrainers($this->batch, [$this->amit->id], $this->org->admin);
    $service->assignTrainers($other, [$this->amit->id], $this->org->admin);

    expect($this->amit->trainedBatches()->orderBy('batches.id')->pluck('batches.id')->all())->toBe([$this->batch->id, $other->id]);
});

/*
| Removal
*/

test('removing a trainer deletes only the assignment, never the user, the leads or the batch', function () {
    $lead = Lead::factory()->assignedTo($this->org->rahul)->create();
    $this->batch->leads()->attach($lead->id);
    $before = $lead->fresh()->only('assigned_to', 'status_id', 'updated_at');
    app(BatchService::class)->assignTrainers($this->batch, [$this->amit->id, $this->neha->id], $this->org->admin);

    $this->actingAs($this->org->manager)->delete("/batches/{$this->batch->id}/trainers/{$this->amit->id}")
        ->assertRedirect()->assertSessionHas('success', 'Amit Shah removed from October Batch. No leads or other batch data were changed.');

    expect(batchTrainerIds($this->batch))->toBe([$this->neha->id])
        ->and($this->amit->fresh())->not->toBeNull()
        ->and($this->amit->fresh()->is_active)->toBeTrue()
        ->and($this->amit->fresh()->isTrainer())->toBeTrue()
        ->and($this->batch->fresh()->trashed())->toBeFalse()
        ->and($this->batch->leads()->pluck('leads.id')->all())->toBe([$lead->id])
        ->and($lead->fresh()->only('assigned_to', 'status_id', 'updated_at'))->toEqual($before);

    expect(AuditLog::where('action', 'BATCH_TRAINER_REMOVED')->sole()->new_values_json)->toBe(['batch_id' => $this->batch->id, 'trainer_id' => $this->amit->id]);
});

test('several trainers are removed in one request and audited as one bulk event', function () {
    app(BatchService::class)->assignTrainers($this->batch, [$this->amit->id, $this->neha->id, $this->kiran->id], $this->org->admin);

    $this->actingAs($this->org->manager)->delete("/batches/{$this->batch->id}/trainers", ['trainer_ids' => [$this->amit->id, $this->neha->id]])
        ->assertSessionHasNoErrors();

    expect(batchTrainerIds($this->batch))->toBe([$this->kiran->id])
        ->and(AuditLog::where('action', 'BATCH_TRAINERS_BULK_REMOVED')->sole()->new_values_json)->toBe(['batch_id' => $this->batch->id, 'trainer_count' => 2]);
});

test('deleting a batch removes its trainer assignments but keeps the users', function () {
    app(BatchService::class)->assignTrainers($this->batch, [$this->amit->id], $this->org->admin);

    $this->actingAs($this->org->admin)->delete("/batches/{$this->batch->id}")->assertRedirect('/batches');

    expect(DB::table('batch_trainers')->count())->toBe(0)->and($this->amit->fresh())->not->toBeNull();
});

/*
| Validation: only active Trainers
*/

test('non-trainers, inactive or deleted trainers and unknown ids cannot be assigned', function () {
    $inactive = User::factory()->trainer()->inactive()->create();
    $deleted = User::factory()->trainer()->create();
    $deleted->delete();

    foreach ([$this->org->rahul->id, $this->org->manager->id, $this->org->admin->id, $this->org->super->id, $inactive->id, $deleted->id] as $id) {
        $this->actingAs($this->org->admin)->post("/batches/{$this->batch->id}/trainers", ['trainer_ids' => [$this->amit->id, $id]])
            ->assertSessionHasErrors(['trainer_ids' => 'One or more selected users are not active trainers.']);
    }
    $this->actingAs($this->org->admin)->post("/batches/{$this->batch->id}/trainers", ['trainer_ids' => [999999]])
        ->assertSessionHasErrors('trainer_ids.0');
    $this->actingAs($this->org->admin)->post("/batches/{$this->batch->id}/trainers", ['trainer_ids' => []])
        ->assertSessionHasErrors('trainer_ids');

    expect(DB::table('batch_trainers')->count())->toBe(0)
        ->and(AuditLog::where('action', 'like', 'BATCH_TRAINER%')->count())->toBe(0);
});

test('a deactivated trainer keeps past assignments, shown as inactive, but cannot be newly assigned', function () {
    $other = Batch::factory()->create();
    app(BatchService::class)->assignTrainers($this->batch, [$this->amit->id], $this->org->admin);
    $this->amit->forceFill(['is_active' => false])->save();

    expect(batchTrainerIds($this->batch))->toBe([$this->amit->id]);
    $trainers = $this->actingAs($this->org->admin)->get("/batches/{$this->batch->id}")->assertOk()->inertiaProps('batch.trainers');
    expect($trainers)->toHaveCount(1)->and($trainers[0])->toMatchArray(['id' => $this->amit->id, 'name' => 'Amit Shah', 'active' => false]);

    expect(fn () => app(BatchService::class)->assignTrainers($other, [$this->amit->id], $this->org->admin))->toThrow(ValidationException::class);

    $ids = collect($this->actingAs($this->org->admin)->getJson('/batches/trainer-search')->json('results'))->pluck('id');
    expect($ids)->not->toContain($this->amit->id);
});

/*
| Permissions (hand-crafted requests)
*/

test('users without batch.manage_trainers cannot add, remove or search trainers even with known ids', function () {
    app(BatchService::class)->assignTrainers($this->batch, [$this->amit->id], $this->org->admin);

    foreach ([$this->org->rahul, $this->amit] as $user) {
        $this->actingAs($user)->post("/batches/{$this->batch->id}/trainers", ['trainer_ids' => [$this->neha->id]])->assertForbidden();
        $this->actingAs($user)->delete("/batches/{$this->batch->id}/trainers/{$this->amit->id}")->assertForbidden();
        $this->actingAs($user)->delete("/batches/{$this->batch->id}/trainers", ['trainer_ids' => [$this->amit->id]])->assertForbidden();
        $this->actingAs($user)->getJson('/batches/trainer-search')->assertForbidden();
    }

    $denied = setPermission($this->org->manager, Permissions::BATCH_MANAGE_TRAINERS, 'deny');
    $this->actingAs($denied)->post("/batches/{$this->batch->id}/trainers", ['trainer_ids' => [$this->neha->id]])->assertForbidden();

    expect(batchTrainerIds($this->batch))->toBe([$this->amit->id]);
});

test('the permission, not the role name, decides who can assign trainers', function () {
    $rahul = setPermission($this->org->rahul, Permissions::BATCH_MANAGE_TRAINERS);

    $this->actingAs($rahul)->post("/batches/{$this->batch->id}/trainers", ['trainer_ids' => [$this->amit->id]])->assertSessionHasNoErrors();
    $this->actingAs($rahul)->delete("/batches/{$this->batch->id}/trainers/{$this->amit->id}")->assertSessionHasNoErrors();

    expect(AuditLog::where('action', 'like', 'BATCH_TRAINER%')->pluck('action')->all())->toBe(['BATCH_TRAINER_ADDED', 'BATCH_TRAINER_REMOVED']);
});

test('the batch page exposes trainer actions only to permitted users', function () {
    $manager = $this->actingAs($this->org->manager)->get("/batches/{$this->batch->id}")->inertiaProps('can');
    $exec = $this->actingAs($this->org->rahul)->get("/batches/{$this->batch->id}")->inertiaProps('can');

    expect($manager)->toMatchArray(['addTrainers' => true, 'removeTrainers' => true])
        ->and($exec)->toMatchArray(['addTrainers' => false, 'removeTrainers' => false]);
});

/*
| Archived batches
*/

test('archived batches keep and show their trainers, allow removal and reject new trainers', function () {
    app(BatchService::class)->assignTrainers($this->batch, [$this->amit->id, $this->neha->id], $this->org->admin);
    $this->batch->forceFill(['status' => 'archived'])->save();

    $page = $this->actingAs($this->org->manager)->get("/batches/{$this->batch->id}")->assertOk();
    expect(collect($page->inertiaProps('batch.trainers'))->pluck('name')->all())->toBe(['Amit Shah', 'Neha Joshi'])
        ->and($page->inertiaProps('can'))->toMatchArray(['addTrainers' => false, 'removeTrainers' => true]);

    $this->actingAs($this->org->manager)->post("/batches/{$this->batch->id}/trainers", ['trainer_ids' => [$this->kiran->id]])->assertForbidden();
    expect(fn () => app(BatchService::class)->assignTrainers($this->batch->fresh(), [$this->kiran->id], $this->org->admin))
        ->toThrow(ValidationException::class, 'archived');

    $this->actingAs($this->org->manager)->delete("/batches/{$this->batch->id}/trainers/{$this->neha->id}")->assertSessionHasNoErrors();
    expect(batchTrainerIds($this->batch))->toBe([$this->amit->id]);
});

/*
| Create / edit
*/

test('a batch is created with trainers and leads in one operation', function () {
    $leads = Lead::factory()->count(3)->assignedTo($this->org->manager)->create();

    $response = $this->actingAs($this->org->manager)->post('/batches', [
        'name' => 'October Batch 2',
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-31',
        'trainer_ids' => [$this->amit->id, $this->neha->id],
        'lead_ids' => $leads->pluck('id')->all(),
    ]);

    $batch = Batch::where('name', 'October Batch 2')->sole();
    $response->assertRedirect("/batches/{$batch->id}")->assertSessionHas('success', fn ($m) => str_contains($m, '2 trainers assigned'));
    expect(batchTrainerIds($batch))->toBe([$this->amit->id, $this->neha->id])
        ->and($batch->leads()->count())->toBe(3)
        ->and(AuditLog::where('action', 'BATCH_TRAINERS_BULK_ADDED')->where('entity_id', $batch->id)->count())->toBe(1);
});

test('a batch can still be created without trainers', function () {
    $this->actingAs($this->org->manager)->post('/batches', ['name' => 'No trainer yet'])->assertSessionHasNoErrors();

    $batch = Batch::where('name', 'No trainer yet')->sole();
    expect(batchTrainerIds($batch))->toBe([])
        ->and($this->actingAs($this->org->manager)->get("/batches/{$batch->id}")->inertiaProps('batch.trainers'))->toBe([]);
});

test('an invalid trainer leaves no partially created batch', function () {
    $lead = Lead::factory()->assignedTo($this->org->manager)->create();

    $this->actingAs($this->org->manager)->post('/batches', ['name' => 'Half made', 'lead_ids' => [$lead->id], 'trainer_ids' => [$this->amit->id, $this->org->rahul->id]])
        ->assertSessionHasErrors('trainer_ids');

    expect(Batch::where('name', 'Half made')->exists())->toBeFalse()
        ->and(DB::table('batch_trainers')->count())->toBe(0)
        ->and(DB::table('batch_leads')->count())->toBe(0);
});

test('creating a batch with trainers needs batch.manage_trainers', function () {
    $manager = setPermission($this->org->manager, Permissions::BATCH_MANAGE_TRAINERS, 'deny');

    $this->actingAs($manager)->post('/batches', ['name' => 'Sneaky', 'trainer_ids' => [$this->amit->id]])->assertForbidden();
    expect(Batch::where('name', 'Sneaky')->exists())->toBeFalse();

    expect($this->actingAs($manager)->get('/batches/create')->inertiaProps('can.manageTrainers'))->toBeFalse()
        ->and($this->actingAs($this->org->admin)->get('/batches/create')->inertiaProps('can.manageTrainers'))->toBeTrue();
});

test('the edit form replaces trainers, and leaves them alone when trainer_ids is not sent', function () {
    app(BatchService::class)->assignTrainers($this->batch, [$this->amit->id, $this->neha->id], $this->org->admin);
    $url = "/batches/{$this->batch->id}";

    $form = $this->actingAs($this->org->manager)->get("{$url}/edit")->inertiaProps();
    expect(collect($form['batch']['trainers'])->pluck('id')->all())->toBe([$this->amit->id, $this->neha->id])
        ->and($form['can']['manageTrainers'])->toBeTrue();

    $this->actingAs($this->org->manager)->put($url, ['name' => 'October Batch', 'trainer_ids' => [$this->neha->id, $this->kiran->id]])->assertSessionHasNoErrors();
    expect(batchTrainerIds($this->batch))->toBe([$this->neha->id, $this->kiran->id])
        ->and(AuditLog::where('action', 'BATCH_TRAINER_ADDED')->count())->toBe(1)
        ->and(AuditLog::where('action', 'BATCH_TRAINER_REMOVED')->count())->toBe(1);

    $this->actingAs($this->org->manager)->put($url, ['name' => 'Renamed only'])->assertSessionHasNoErrors();
    expect(batchTrainerIds($this->batch))->toBe([$this->neha->id, $this->kiran->id]);

    $this->actingAs($this->org->manager)->put($url, ['name' => 'Renamed only', 'trainer_ids' => []])->assertSessionHasNoErrors();
    expect(batchTrainerIds($this->batch))->toBe([]);
});

test('editing keeps a deactivated trainer but rejects newly added ineligible users', function () {
    app(BatchService::class)->assignTrainers($this->batch, [$this->amit->id], $this->org->admin);
    $this->amit->forceFill(['is_active' => false])->save();
    $url = "/batches/{$this->batch->id}";

    $this->actingAs($this->org->manager)->put($url, ['name' => 'Kept', 'trainer_ids' => [$this->amit->id, $this->neha->id]])->assertSessionHasNoErrors();
    expect(batchTrainerIds($this->batch))->toBe([$this->amit->id, $this->neha->id]);

    $this->actingAs($this->org->manager)->put($url, ['name' => 'Must not save', 'trainer_ids' => [$this->amit->id, $this->org->rahul->id]])
        ->assertSessionHasErrors('trainer_ids');
    expect($this->batch->fresh()->name)->toBe('Kept')
        ->and(batchTrainerIds($this->batch))->toBe([$this->amit->id, $this->neha->id]);
});

test('editing an archived batch can remove trainers but not add them', function () {
    app(BatchService::class)->assignTrainers($this->batch, [$this->amit->id, $this->neha->id], $this->org->admin);
    $this->batch->forceFill(['status' => 'archived'])->save();
    $url = "/batches/{$this->batch->id}";

    $this->actingAs($this->org->admin)->put($url, ['name' => 'October Batch', 'trainer_ids' => [$this->amit->id, $this->kiran->id]])
        ->assertSessionHasErrors(['trainer_ids' => 'This batch is archived and cannot receive new trainers.']);
    expect(batchTrainerIds($this->batch))->toBe([$this->amit->id, $this->neha->id]);

    $this->actingAs($this->org->admin)->put($url, ['name' => 'October Batch', 'trainer_ids' => [$this->amit->id]])->assertSessionHasNoErrors();
    expect(batchTrainerIds($this->batch))->toBe([$this->amit->id]);
});

test('updating trainers through the edit form needs batch.manage_trainers', function () {
    app(BatchService::class)->assignTrainers($this->batch, [$this->amit->id], $this->org->admin);
    $manager = setPermission($this->org->manager, Permissions::BATCH_MANAGE_TRAINERS, 'deny');

    $this->actingAs($manager)->put("/batches/{$this->batch->id}", ['name' => 'X', 'trainer_ids' => []])->assertForbidden();
    expect(batchTrainerIds($this->batch))->toBe([$this->amit->id])->and($this->batch->fresh()->name)->toBe('October Batch');
});

/*
| Lead list → Create new batch
*/

test('selected leads, a new batch and its trainers are saved together from the lead list', function () {
    $leads = Lead::factory()->count(25)->assignedTo($this->org->manager)->create();

    expect($this->actingAs($this->org->manager)->get('/leads')->inertiaProps('can.manageBatchTrainers'))->toBeTrue()
        ->and($this->actingAs($this->org->rahul)->get('/leads')->inertiaProps('can.manageBatchTrainers'))->toBeFalse();

    $this->actingAs($this->org->manager)->post('/batches', [
        'name' => 'From lead list', 'description' => 'Campaign', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31',
        'status' => 'active', 'lead_ids' => $leads->pluck('id')->all(), 'trainer_ids' => [$this->amit->id, $this->neha->id],
    ])->assertSessionHasNoErrors();

    $batch = Batch::where('name', 'From lead list')->sole();
    expect($batch->leads()->count())->toBe(25)
        ->and(batchTrainerIds($batch))->toBe([$this->amit->id, $this->neha->id])
        ->and($batch->start_date->toDateString())->toBe('2026-10-01');
});

/*
| Lead visibility is unchanged
*/

test('assigning a trainer never exposes leads the trainer could not already see', function () {
    $lead = Lead::factory()->assignedTo($this->org->rahul)->create(['first_name' => 'Hidden', 'last_name' => 'Customer']);
    $this->batch->leads()->attach($lead->id);
    app(BatchService::class)->assignTrainers($this->batch, [$this->amit->id], $this->org->admin);

    $page = $this->actingAs($this->amit)->get("/batches/{$this->batch->id}")->assertOk();
    expect($page->inertiaProps('summary.total'))->toBe(0)
        ->and($page->inertiaProps('leads.data'))->toBe([])
        ->and($page->getContent())->not->toContain($lead->lead_number);
    $this->actingAs($this->amit)->get("/leads/{$lead->id}")->assertForbidden();

    $withOwnLeads = setPermission($this->amit, Permissions::LEAD_VIEW);
    expect($this->actingAs($withOwnLeads)->get("/batches/{$this->batch->id}")->inertiaProps('summary.total'))->toBe(0);
    $this->actingAs($withOwnLeads)->get("/leads/{$lead->id}")->assertForbidden();
});

/*
| Batch list, "My batches" filter and trainer search
*/

test('the batch list shows trainers and filters by trainer or "my batches"', function () {
    $other = Batch::factory()->create(['name' => 'Unassigned batch']);
    app(BatchService::class)->assignTrainers($this->batch, [$this->amit->id, $this->neha->id, $this->kiran->id], $this->org->admin);

    $props = $this->actingAs($this->org->manager)->get('/batches')->inertiaProps();
    $row = collect($props['batches']['data'])->firstWhere('id', $this->batch->id);
    expect(collect($row['trainers'])->pluck('name')->all())->toBe(['Amit Shah', 'Kiran Rao', 'Neha Joshi'])
        ->and(collect($props['batches']['data'])->firstWhere('id', $other->id)['trainers'])->toBe([])
        ->and(collect($props['trainerOptions'])->pluck('id')->sort()->values()->all())->toBe([$this->amit->id, $this->neha->id, $this->kiran->id])
        ->and($props['isTrainer'])->toBeFalse();

    $filtered = $this->actingAs($this->org->manager)->get("/batches?trainer={$this->neha->id}")->inertiaProps('batches.data');
    expect(collect($filtered)->pluck('id')->all())->toBe([$this->batch->id]);

    $mine = $this->actingAs($this->amit)->get('/batches?trainer=me')->assertOk();
    expect(collect($mine->inertiaProps('batches.data'))->pluck('id')->all())->toBe([$this->batch->id])
        ->and($mine->inertiaProps('isTrainer'))->toBeTrue();

    $this->actingAs($this->org->manager)->get('/batches?trainer=abc')->assertSessionHasErrors('trainer');
});

test('trainer search returns only active trainers, searched server-side, without contact details', function () {
    User::factory()->trainer()->inactive()->create(['name' => 'Amit Inactive']);
    app(BatchService::class)->assignTrainers($this->batch, [$this->neha->id], $this->org->admin);

    $all = $this->actingAs($this->org->manager)->getJson("/batches/trainer-search?batch={$this->batch->id}")->assertOk()->json('results');
    expect(collect($all)->pluck('name')->all())->toBe(['Amit Shah', 'Kiran Rao', 'Neha Joshi'])
        ->and(collect($all)->firstWhere('id', $this->neha->id)['assigned'])->toBeTrue()
        ->and(array_keys($all[0]))->not->toContain('email')
        ->and(array_keys($all[0]))->not->toContain('phone')
        ->and($all[0]['role'])->toBe('Trainer');

    foreach (['Amit', 'amit.trainer@', 'TR-101'] as $term) {
        expect(collect($this->actingAs($this->org->manager)->getJson('/batches/trainer-search?q='.urlencode($term))->json('results'))->pluck('id')->all())
            ->toBe([$this->amit->id]);
    }
    expect($this->actingAs($this->org->manager)->getJson('/batches/trainer-search?q=Rahul')->json('results'))->toBe([]);
});

/*
| Notifications
*/

test('a trainer is notified when assigned and removed, linking to the batch', function () {
    $this->actingAs($this->org->manager)->post("/batches/{$this->batch->id}/trainers", ['trainer_ids' => [$this->amit->id]]);

    $assigned = batchTrainerNotifications($this->amit);
    expect($assigned)->toHaveCount(1)
        ->and($assigned[0]->data)->toMatchArray([
            'category' => 'batch',
            'event' => BatchTrainerNotification::ASSIGNED,
            'message' => 'You have been assigned to Batch "October Batch".',
            'batch_id' => $this->batch->id,
            'url' => "/batches/{$this->batch->id}",
        ]);

    $this->actingAs($this->amit)->get("/notifications/{$assigned[0]->id}/open")->assertRedirect("/batches/{$this->batch->id}");

    $this->actingAs($this->org->manager)->delete("/batches/{$this->batch->id}/trainers/{$this->amit->id}");
    $removed = batchTrainerNotifications($this->amit)->firstWhere('data.event', BatchTrainerNotification::REMOVED);
    expect($removed->data['message'])->toBe('You have been removed from Batch "October Batch".')
        ->and((new BatchTrainerNotification($this->batch, false))->via($this->amit))->toBe(['database'])
        ->and((new BatchTrainerNotification($this->batch))->toBrowserPush($this->amit))->toMatchArray(['event' => 'BATCH_ASSIGNED']);
});

test('assigning yourself sends no notification and a batch notification without batch access is stale', function () {
    $self = setPermission($this->amit, Permissions::BATCH_MANAGE_TRAINERS);
    $this->actingAs($self)->post("/batches/{$this->batch->id}/trainers", ['trainer_ids' => [$self->id]])->assertSessionHasNoErrors();
    expect(batchTrainerNotifications($self))->toHaveCount(0);

    app(BatchService::class)->assignTrainers($this->batch, [$this->neha->id], $this->org->admin);
    $neha = setPermission($this->neha, Permissions::BATCH_VIEW, 'deny');
    $id = batchTrainerNotifications($neha)->sole()->id;

    $this->actingAs($neha)->get("/notifications/{$id}/open")->assertRedirect('/notifications');
});
