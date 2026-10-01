<?php

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Lead;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->org = salesOrg();
    $this->batch = Batch::factory()->create(['name' => 'October Campaign']);
    $this->rahulLead = Lead::factory()->assignedTo($this->org->rahul)->create(['first_name' => 'RahulLead']);
    $this->priyaLead = Lead::factory()->assignedTo($this->org->priya)->create(['first_name' => 'PriyaLead']);
    $this->unassigned = Lead::factory()->create(['first_name' => 'QueuedLead', 'assigned_to' => null]);
});

test('an executive cannot add another user\'s lead by submitting its id', function () {
    $this->actingAs($this->org->rahul)
        ->post("/batches/{$this->batch->id}/leads", ['lead_ids' => [$this->priyaLead->id]])
        ->assertSessionHasErrors('lead_ids');

    expect(DB::table('batch_leads')->count())->toBe(0);
});

test('one hidden id rejects the whole request, so visible ids in it are not added either', function () {
    $this->actingAs($this->org->rahul)
        ->post("/batches/{$this->batch->id}/leads", ['lead_ids' => [$this->rahulLead->id, $this->unassigned->id]])
        ->assertSessionHasErrors('lead_ids');

    expect(DB::table('batch_leads')->count())->toBe(0)
        ->and(AuditLog::where('action', 'like', 'BATCH_LEAD%')->count())->toBe(0);
});

test('hidden ids cannot be smuggled in when creating a batch', function () {
    $manager = setPermission($this->org->manager, Permissions::BATCH_MANAGE_LEADS);

    $this->actingAs($manager)->post('/batches', ['name' => 'Sneaky', 'lead_ids' => [$this->priyaLead->id]])
        ->assertSessionHasErrors('lead_ids');

    expect(Batch::where('name', 'Sneaky')->exists())->toBeFalse();
});

test('lead ids on create require batch.manage_leads', function () {
    $manager = setPermission($this->org->manager, Permissions::BATCH_MANAGE_LEADS, 'deny');

    $this->actingAs($manager)->post('/batches', ['name' => 'With leads', 'lead_ids' => [$this->rahulLead->id]])->assertForbidden();
    expect(Batch::where('name', 'With leads')->exists())->toBeFalse();
});

test('the batch page shows only leads the viewer can see, and counts match', function () {
    $this->batch->leads()->attach([$this->rahulLead->id, $this->priyaLead->id, $this->unassigned->id]);

    $rahul = $this->actingAs($this->org->rahul)->get("/batches/{$this->batch->id}")->assertOk();
    expect(collect($rahul->inertiaProps('leads.data'))->pluck('id')->all())->toBe([$this->rahulLead->id])
        ->and($rahul->inertiaProps('summary.total'))->toBe(1);

    $admin = $this->actingAs($this->org->admin)->get("/batches/{$this->batch->id}")->assertOk();
    expect($admin->inertiaProps('leads.data'))->toHaveCount(3)
        ->and($admin->inertiaProps('summary.total'))->toBe(3);
});

test('batch page filters cannot widen visibility', function () {
    $this->batch->leads()->attach([$this->rahulLead->id, $this->priyaLead->id]);

    $ids = collect($this->actingAs($this->org->rahul)
        ->get("/batches/{$this->batch->id}?assignee={$this->org->priya->id}&search=PriyaLead")
        ->inertiaProps('leads.data'))->pluck('id')->all();

    expect($ids)->not->toContain($this->priyaLead->id);
});

test('an executive cannot remove a lead they cannot see', function () {
    $this->batch->leads()->attach([$this->priyaLead->id]);

    $this->actingAs($this->org->rahul)->delete("/batches/{$this->batch->id}/leads/{$this->priyaLead->id}")->assertForbidden();
    $this->actingAs($this->org->rahul)->delete("/batches/{$this->batch->id}/leads", ['lead_ids' => [$this->priyaLead->id]])
        ->assertSessionHasErrors('lead_ids');

    expect(DB::table('batch_leads')->where('lead_id', $this->priyaLead->id)->count())->toBe(1);
});

test('membership changes require batch.manage_leads', function () {
    $user = setPermission($this->org->rahul, Permissions::BATCH_MANAGE_LEADS, 'deny');
    $this->batch->leads()->attach([$this->rahulLead->id]);

    $this->actingAs($user)->post("/batches/{$this->batch->id}/leads", ['lead_ids' => [$this->rahulLead->id]])->assertForbidden();
    $this->actingAs($user)->delete("/batches/{$this->batch->id}/leads/{$this->rahulLead->id}")->assertForbidden();
    $this->actingAs($user)->delete("/batches/{$this->batch->id}/leads", ['lead_ids' => [$this->rahulLead->id]])->assertForbidden();
    $this->actingAs($user)->getJson('/batches/lead-search')->assertForbidden();

    expect(DB::table('batch_leads')->count())->toBe(1);
});

test('the lead selector search is visibility-scoped, paginated and returns limited fields', function () {
    Lead::factory()->count(25)->assignedTo($this->org->rahul)->create();
    $this->batch->leads()->attach([$this->rahulLead->id]);

    $json = $this->actingAs($this->org->rahul)->getJson("/batches/lead-search?batch={$this->batch->id}")->assertOk()->json();

    expect($json['total'])->toBe(26)
        ->and($json['data'])->toHaveCount(20)
        ->and($json['last_page'])->toBe(2)
        ->and(array_keys($json['data'][0]))->toEqualCanonicalizing(['id', 'lead_number', 'full_name', 'company_name', 'phone', 'email', 'status', 'source', 'owner', 'in_batch']);

    $ids = collect($this->actingAs($this->org->rahul)->getJson('/batches/lead-search?q=PriyaLead')->json('data'))->pluck('id');
    expect($ids)->not->toContain($this->priyaLead->id);

    $second = collect($this->actingAs($this->org->rahul)->getJson("/batches/lead-search?batch={$this->batch->id}&page=2")->json('data'));
    $all = collect($json['data'])->merge($second);
    expect($all->firstWhere('id', $this->rahulLead->id)['in_batch'])->toBeTrue();
});

test('batch lookup hides archived batches', function () {
    $archived = Batch::factory()->archived()->create(['name' => 'October Old']);

    $ids = collect($this->actingAs($this->org->rahul)->getJson('/batches/lookup?q=October')->assertOk()->json('results'))->pluck('id');

    expect($ids)->toContain($this->batch->id)->not->toContain($archived->id);
});

test('the lead number of a hidden lead never appears on the batch page', function () {
    $this->batch->leads()->attach([$this->priyaLead->id]);

    $this->actingAs($this->org->rahul)->get("/batches/{$this->batch->id}")
        ->assertOk()
        ->assertDontSee($this->priyaLead->lead_number);
});
