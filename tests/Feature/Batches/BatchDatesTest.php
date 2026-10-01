<?php

use App\Enums\BatchStatus;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->org = salesOrg();
});

function rawBatchDates(Batch $batch): array
{
    $row = DB::table('batches')->where('id', $batch->id)->first(['start_date', 'end_date']);

    return [
        $row->start_date === null ? null : substr($row->start_date, 0, 10),
        $row->end_date === null ? null : substr($row->end_date, 0, 10),
    ];
}

test('a batch is created with a start and end date', function () {
    $this->actingAs($this->org->manager)
        ->post('/batches', ['name' => 'October Campaign', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31'])
        ->assertSessionHasNoErrors();

    $batch = Batch::sole();
    expect(rawBatchDates($batch))->toBe(['2026-10-01', '2026-10-31'])
        ->and($batch->start_date->toDateString())->toBe('2026-10-01');

    $log = AuditLog::where('action', 'BATCH_CREATED')->sole();
    expect($log->new_values_json)->toMatchArray(['start_date' => '2026-10-01', 'end_date' => '2026-10-31']);
});

test('dates are optional', function () {
    $this->actingAs($this->org->manager)->post('/batches', ['name' => 'No dates', 'start_date' => '', 'end_date' => ''])->assertSessionHasNoErrors();
    $this->actingAs($this->org->manager)->post('/batches', ['name' => 'Start only', 'start_date' => '2026-10-01'])->assertSessionHasNoErrors();
    $this->actingAs($this->org->manager)->post('/batches', ['name' => 'End only', 'end_date' => '2026-10-31'])->assertSessionHasNoErrors();

    expect(rawBatchDates(Batch::where('name', 'No dates')->sole()))->toBe([null, null])
        ->and(rawBatchDates(Batch::where('name', 'Start only')->sole()))->toBe(['2026-10-01', null])
        ->and(rawBatchDates(Batch::where('name', 'End only')->sole()))->toBe([null, '2026-10-31']);
});

test('the end date cannot be before the start date', function () {
    $this->actingAs($this->org->manager)
        ->post('/batches', ['name' => 'Backwards', 'start_date' => '2026-10-15', 'end_date' => '2026-10-10'])
        ->assertSessionHasErrors(['end_date' => 'The end date must be on or after the start date.']);

    expect(Batch::count())->toBe(0);

    $batch = Batch::factory()->create();
    $this->actingAs($this->org->manager)
        ->put("/batches/{$batch->id}", ['name' => $batch->name, 'start_date' => '2026-10-31', 'end_date' => '2026-10-01'])
        ->assertSessionHasErrors(['end_date' => 'The end date must be on or after the start date.']);
    expect(rawBatchDates($batch))->toBe([null, null]);
});

test('invalid dates are rejected', function () {
    $this->actingAs($this->org->manager)
        ->post('/batches', ['name' => 'Bad', 'start_date' => 'not-a-date', 'end_date' => '2026-02-30'])
        ->assertSessionHasErrors(['start_date', 'end_date']);
});

test('the same start and end date is valid', function () {
    $this->actingAs($this->org->manager)
        ->post('/batches', ['name' => 'One day', 'start_date' => '2026-10-01', 'end_date' => '2026-10-01'])
        ->assertSessionHasNoErrors();

    expect(rawBatchDates(Batch::sole()))->toBe(['2026-10-01', '2026-10-01']);
});

test('dates can be updated and the change is audited as plain dates', function () {
    $batch = Batch::factory()->create(['name' => 'October Campaign']);

    $this->actingAs($this->org->manager)
        ->put("/batches/{$batch->id}", ['name' => 'October Campaign', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31'])
        ->assertRedirect("/batches/{$batch->id}");
    expect(rawBatchDates($batch))->toBe(['2026-10-01', '2026-10-31']);

    $this->actingAs($this->org->manager)
        ->put("/batches/{$batch->id}", ['name' => 'October Campaign', 'start_date' => '2026-10-01', 'end_date' => '2026-11-15']);

    $log = AuditLog::where('action', 'BATCH_UPDATED')->latest('id')->first();
    expect($log->old_values_json)->toBe(['end_date' => '2026-10-31'])
        ->and($log->new_values_json)->toBe(['end_date' => '2026-11-15']);
});

test('dates can be cleared back to null', function () {
    $batch = Batch::factory()->create(['start_date' => '2026-10-01', 'end_date' => '2026-10-31']);

    $this->actingAs($this->org->manager)
        ->put("/batches/{$batch->id}", ['name' => $batch->name, 'start_date' => '', 'end_date' => ''])
        ->assertSessionHasNoErrors();

    expect(rawBatchDates($batch))->toBe([null, null]);
});

test('saving without changing the dates writes no audit entry', function () {
    $batch = Batch::factory()->create(['name' => 'Steady', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31']);

    $this->actingAs($this->org->manager)
        ->put("/batches/{$batch->id}", ['name' => 'Steady', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31']);

    expect(AuditLog::where('action', 'BATCH_UPDATED')->count())->toBe(0);
});

test('dates on an archived batch can be edited without un-archiving it', function () {
    $batch = Batch::factory()->archived()->create();

    $this->actingAs($this->org->admin)->put("/batches/{$batch->id}", ['name' => $batch->name, 'start_date' => '2026-10-01']);

    expect($batch->fresh())->status->toBe(BatchStatus::Archived)
        ->and(rawBatchDates($batch))->toBe(['2026-10-01', null]);
});

test('dates never change the manually set status', function () {
    $this->actingAs($this->org->manager)
        ->post('/batches', ['name' => 'Long over', 'status' => 'active', 'start_date' => '2020-01-01', 'end_date' => '2020-01-31']);

    expect(Batch::sole()->status)->toBe(BatchStatus::Active);
});

test('a batch created from the lead list stores its dates and leads', function () {
    $lead = Lead::factory()->assignedTo($this->org->rahul)->create();

    $response = $this->actingAs($this->org->admin)
        ->post('/batches', ['name' => 'From list', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'lead_ids' => [$lead->id]]);

    $batch = Batch::sole();
    $response->assertRedirect("/batches/{$batch->id}");
    expect(rawBatchDates($batch))->toBe(['2026-10-01', '2026-10-31'])
        ->and($batch->leads()->pluck('leads.id')->all())->toBe([$lead->id]);

    $this->actingAs($this->org->admin)
        ->post('/batches', ['name' => 'Backwards', 'start_date' => '2026-10-31', 'end_date' => '2026-10-01', 'lead_ids' => [$lead->id]])
        ->assertSessionHasErrors('end_date');
    expect(Batch::count())->toBe(1);
});

test('list, detail and edit pages return plain Y-m-d dates', function () {
    $batch = Batch::factory()->create(['start_date' => '2026-10-01', 'end_date' => '2026-10-31']);

    $row = collect($this->actingAs($this->org->admin)->get('/batches')->assertOk()->inertiaProps('batches.data'))->firstWhere('id', $batch->id);
    expect($row)->toMatchArray(['start_date' => '2026-10-01', 'end_date' => '2026-10-31']);

    $show = $this->actingAs($this->org->admin)->get("/batches/{$batch->id}")->assertOk();
    expect($show->inertiaProps('batch.start_date'))->toBe('2026-10-01')->and($show->inertiaProps('batch.end_date'))->toBe('2026-10-31');

    $edit = $this->actingAs($this->org->admin)->get("/batches/{$batch->id}/edit")->assertOk();
    expect($edit->inertiaProps('batch.start_date'))->toBe('2026-10-01')->and($edit->inertiaProps('batch.end_date'))->toBe('2026-10-31');
});

test('batches with null dates render everywhere', function () {
    $batch = Batch::factory()->create();
    $batch->leads()->attach(Lead::factory()->assignedTo($this->org->rahul)->create()->id);

    $row = collect($this->actingAs($this->org->rahul)->get('/batches')->assertOk()->inertiaProps('batches.data'))->firstWhere('id', $batch->id);
    expect($row['start_date'])->toBeNull()->and($row['end_date'])->toBeNull();

    $show = $this->actingAs($this->org->rahul)->get("/batches/{$batch->id}")->assertOk();
    expect($show->inertiaProps('batch.start_date'))->toBeNull()->and($show->inertiaProps('leads.data'))->toHaveCount(1);

    expect($this->actingAs($this->org->manager)->get("/batches/{$batch->id}/edit")->assertOk()->inertiaProps('batch.end_date'))->toBeNull();
});

test('the batch list filters by start and end date ranges, inclusive', function () {
    $october = Batch::factory()->create(['name' => 'October', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31']);
    $november = Batch::factory()->create(['name' => 'November', 'start_date' => '2026-11-01', 'end_date' => '2026-11-30']);
    $undated = Batch::factory()->create(['name' => 'Undated']);

    $ids = fn (string $query) => collect($this->actingAs($this->org->admin)->get('/batches?'.$query)->assertOk()->inertiaProps('batches.data'))->pluck('id')->sort()->values()->all();

    expect($ids(''))->toBe(collect([$october->id, $november->id, $undated->id])->sort()->values()->all())
        ->and($ids('start_from=2026-10-01&start_to=2026-10-01'))->toBe([$october->id])
        ->and($ids('start_from=2026-10-15'))->toBe([$november->id])
        ->and($ids('end_to=2026-10-31'))->toBe([$october->id])
        ->and($ids('end_from=2026-11-30&end_to=2026-11-30'))->toBe([$november->id])
        ->and($ids('start_from=2027-01-01'))->toBe([]);

    $this->actingAs($this->org->admin)->get('/batches?start_from=nonsense')->assertSessionHasErrors('start_from');
});

test('calendar dates are not shifted by the application timezone', function () {
    $originalTz = date_default_timezone_get();
    $originalConfig = config('app.timezone');

    try {
        foreach (['Pacific/Kiritimati', 'America/Los_Angeles'] as $tz) {
            config(['app.timezone' => $tz]);
            date_default_timezone_set($tz);

            $this->actingAs($this->org->manager)->post('/batches', ['name' => "TZ {$tz}", 'start_date' => '2026-10-01', 'end_date' => '2026-10-01']);
            $batch = Batch::where('name', "TZ {$tz}")->sole();

            expect(rawBatchDates($batch))->toBe(['2026-10-01', '2026-10-01'])
                ->and($this->actingAs($this->org->manager)->get("/batches/{$batch->id}")->inertiaProps('batch.start_date'))->toBe('2026-10-01');
        }
    } finally {
        config(['app.timezone' => $originalConfig]);
        date_default_timezone_set($originalTz);
    }
});
