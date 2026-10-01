<?php

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Followup;
use App\Models\Lead;
use App\Services\Batches\BatchService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->org = salesOrg();
    $this->batch = Batch::factory()->create(['name' => 'October Campaign']);
    $this->leads = Lead::factory()->count(3)->assignedTo($this->org->rahul)->create();
});

function memberIds(Batch $batch): array
{
    return DB::table('batch_leads')->where('batch_id', $batch->id)->orderBy('lead_id')->pluck('lead_id')->all();
}

test('a single lead is added and audited with ids only', function () {
    $lead = $this->leads->first();

    $this->actingAs($this->org->rahul)->post("/batches/{$this->batch->id}/leads", ['lead_ids' => [$lead->id]])
        ->assertRedirect()->assertSessionHas('success');

    expect(memberIds($this->batch))->toBe([$lead->id]);
    $row = DB::table('batch_leads')->where('batch_id', $this->batch->id)->first();
    expect((int) $row->added_by)->toBe($this->org->rahul->id)->and($row->created_at)->not->toBeNull();

    $log = AuditLog::where('action', 'BATCH_LEAD_ADDED')->sole();
    expect($log->new_values_json)->toBe(['batch_id' => $this->batch->id, 'lead_id' => $lead->id]);
});

test('multiple leads are added in one request and audited as one bulk event', function () {
    $ids = $this->leads->pluck('id')->sort()->values()->all();

    $this->actingAs($this->org->rahul)->post("/batches/{$this->batch->id}/leads", ['lead_ids' => $ids])->assertRedirect();

    expect(memberIds($this->batch))->toBe($ids);
    $log = AuditLog::where('action', 'BATCH_LEADS_BULK_ADDED')->sole();
    expect($log->new_values_json)->toBe(['batch_id' => $this->batch->id, 'lead_count' => 3]);
});

test('adding the same leads again creates no duplicates and reports them as already present', function () {
    $ids = $this->leads->pluck('id')->all();
    $this->actingAs($this->org->rahul)->post("/batches/{$this->batch->id}/leads", ['lead_ids' => [$ids[0]]]);

    $this->actingAs($this->org->rahul)->post("/batches/{$this->batch->id}/leads", ['lead_ids' => [...$ids, $ids[0]]])
        ->assertRedirect()
        ->assertSessionHas('success', fn (string $msg) => str_contains($msg, '3 selected') && str_contains($msg, '2 added') && str_contains($msg, '1 already'));

    $this->actingAs($this->org->rahul)->post("/batches/{$this->batch->id}/leads", ['lead_ids' => $ids])->assertRedirect();

    expect(DB::table('batch_leads')->where('batch_id', $this->batch->id)->count())->toBe(3)
        ->and(AuditLog::where('action', 'like', 'BATCH_LEAD%')->count())->toBe(2);
});

test('the database rejects a duplicate membership row', function () {
    $lead = $this->leads->first();
    DB::table('batch_leads')->insert(['batch_id' => $this->batch->id, 'lead_id' => $lead->id, 'created_at' => now()]);

    expect(fn () => DB::table('batch_leads')->insert(['batch_id' => $this->batch->id, 'lead_id' => $lead->id, 'created_at' => now()]))
        ->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

test('one lead can belong to several batches', function () {
    $second = Batch::factory()->create();
    $lead = $this->leads->first();

    $this->actingAs($this->org->rahul)->post("/batches/{$this->batch->id}/leads", ['lead_ids' => [$lead->id]]);
    $this->actingAs($this->org->rahul)->post("/batches/{$second->id}/leads", ['lead_ids' => [$lead->id]]);

    expect($lead->batches()->pluck('batches.id')->sort()->values()->all())->toBe(collect([$this->batch->id, $second->id])->sort()->values()->all());
});

test('removing a lead deletes only the membership and leaves the lead untouched', function () {
    $lead = $this->leads->first();
    $this->batch->leads()->attach($this->leads->pluck('id'));
    $before = $lead->fresh()->only('assigned_to', 'status_id', 'next_followup_at', 'deleted_at');

    $this->actingAs($this->org->rahul)->delete("/batches/{$this->batch->id}/leads/{$lead->id}")->assertRedirect();

    expect(memberIds($this->batch))->not->toContain($lead->id)->toHaveCount(2)
        ->and(Lead::find($lead->id))->not->toBeNull()
        ->and($lead->fresh()->only('assigned_to', 'status_id', 'next_followup_at', 'deleted_at'))->toBe($before);

    $log = AuditLog::where('action', 'BATCH_LEAD_REMOVED')->sole();
    expect($log->new_values_json)->toBe(['batch_id' => $this->batch->id, 'lead_id' => $lead->id]);
});

test('bulk remove deletes only the selected memberships', function () {
    $this->batch->leads()->attach($this->leads->pluck('id'));
    $remove = $this->leads->take(2)->pluck('id')->all();

    $this->actingAs($this->org->rahul)->delete("/batches/{$this->batch->id}/leads", ['lead_ids' => $remove])->assertRedirect();

    expect(memberIds($this->batch))->toBe([$this->leads->last()->id])
        ->and(Lead::whereIn('id', $remove)->count())->toBe(2);
    $log = AuditLog::where('action', 'BATCH_LEADS_BULK_REMOVED')->sole();
    expect($log->new_values_json)->toBe(['batch_id' => $this->batch->id, 'lead_count' => 2]);
});

test('archived batches reject new leads but still allow removal', function () {
    $lead = $this->leads->first();
    $this->batch->leads()->attach($lead->id);
    $this->batch->forceFill(['status' => 'archived'])->save();

    $this->actingAs($this->org->rahul)->post("/batches/{$this->batch->id}/leads", ['lead_ids' => [$this->leads->last()->id]])
        ->assertForbidden();
    expect(fn () => app(BatchService::class)->addLeads($this->batch->fresh(), [$this->leads->last()->id], $this->org->rahul))
        ->toThrow(Illuminate\Validation\ValidationException::class);

    $this->actingAs($this->org->rahul)->delete("/batches/{$this->batch->id}/leads/{$lead->id}")->assertRedirect();
    expect(memberIds($this->batch))->toBe([]);
});

test('creating a batch from selected leads adds them and redirects to the new batch', function () {
    $ids = $this->leads->pluck('id')->sort()->values()->all();

    $response = $this->actingAs($this->org->admin)->post('/batches', ['name' => 'From list', 'lead_ids' => $ids]);

    $batch = Batch::where('name', 'From list')->sole();
    $response->assertRedirect("/batches/{$batch->id}");
    expect(memberIds($batch))->toBe($ids);
});

test('batch membership never creates follow-ups, notifications or ownership changes', function () {
    $ids = $this->leads->pluck('id')->all();
    $followups = Followup::count();
    $notifications = DB::table('notifications')->count();

    $this->actingAs($this->org->rahul)->post("/batches/{$this->batch->id}/leads", ['lead_ids' => $ids]);
    $this->actingAs($this->org->rahul)->delete("/batches/{$this->batch->id}/leads", ['lead_ids' => $ids]);

    expect(Followup::count())->toBe($followups)
        ->and(DB::table('notifications')->count())->toBe($notifications)
        ->and(Lead::whereIn('id', $ids)->pluck('assigned_to')->unique()->all())->toBe([$this->org->rahul->id]);
});

test('lead id payloads are validated', function () {
    $url = "/batches/{$this->batch->id}/leads";

    $this->actingAs($this->org->rahul)->post($url, [])->assertSessionHasErrors('lead_ids');
    $this->actingAs($this->org->rahul)->post($url, ['lead_ids' => ['abc']])->assertSessionHasErrors('lead_ids.0');
    $this->actingAs($this->org->rahul)->post($url, ['lead_ids' => range(1, 1001)])->assertSessionHasErrors('lead_ids');
    $this->actingAs($this->org->rahul)->post($url, ['lead_ids' => [999999]])->assertSessionHasErrors('lead_ids');

    expect(memberIds($this->batch))->toBe([]);
});
