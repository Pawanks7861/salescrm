<?php

namespace App\Services\Batches;

use App\Enums\AuditAction;
use App\Enums\BatchStatus;
use App\Models\Batch;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\Batches\BatchTrainerNotification;
use App\Services\AuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * All batch writes. Membership changes touch only batch_leads: a lead's owner,
 * status, follow-ups, meetings, notes and attachments are never modified, and
 * no notification is sent. Every submitted lead id is re-checked against
 * LeadVisibility, so hidden ids cannot be added or removed by crafting a request.
 *
 * Trainer assignment touches only batch_trainers and never grants lead access.
 * Every newly assigned id must be an active user with the Trainer role.
 */
class BatchService
{
    /** Upper bound for lead ids in one request (the lead list pages at most 100). */
    public const MAX_LEADS_PER_REQUEST = 1000;

    public const MAX_TRAINERS_PER_REQUEST = 50;

    private const INSERT_CHUNK = 500;

    public function __construct(
        private readonly BatchNumberService $numbers,
        private readonly AuditService $audit,
    ) {}

    /** Calendar-date columns: stored as Y-m-d exactly as entered, never timezone-converted. */
    private const DATE_FIELDS = ['start_date', 'end_date'];

    /**
     * @param  array{name: string, description?: ?string, status?: ?string, start_date?: ?string, end_date?: ?string}  $data
     * @param  array<int>  $leadIds
     * @param  array<int>  $trainerIds
     * @return array{0: Batch, 1: BatchMembershipResult}
     */
    public function create(array $data, User $actor, array $leadIds = [], array $trainerIds = []): array
    {
        $leadIds = $this->visibleLeadIds($leadIds, $actor);
        $trainerIds = $this->eligibleTrainerIds($trainerIds);
        $assigned = [];

        [$batch, $result] = DB::transaction(function () use ($data, $actor, $leadIds, $trainerIds, &$assigned) {
            $batch = new Batch;
            $batch->fill([
                'name' => trim($data['name']),
                'description' => $this->clean($data['description'] ?? null),
                'start_date' => $this->calendarDate($data['start_date'] ?? null),
                'end_date' => $this->calendarDate($data['end_date'] ?? null),
            ]);
            $batch->forceFill([
                'batch_number' => $this->numbers->next(),
                'status' => $this->creatableStatus($data['status'] ?? null),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();

            $this->audit->log(AuditAction::BatchCreated, 'batches', $batch, "Batch {$batch->batch_number} created",
                null, [
                    'batch_id' => $batch->id,
                    'batch_number' => $batch->batch_number,
                    'status' => $batch->status->value,
                    'start_date' => $batch->start_date?->toDateString(),
                    'end_date' => $batch->end_date?->toDateString(),
                ]);

            $assigned = $this->insertTrainers($batch, $trainerIds, $actor);
            $result = $leadIds === [] ? BatchMembershipResult::none() : $this->insertMembers($batch, $leadIds, $actor);

            return [$batch, $result];
        });

        $this->notifyTrainers($batch, $assigned, $actor, assigned: true);

        return [$batch, $result];
    }

    /**
     * Dates are only touched when present in `$data`; an empty value clears them.
     *
     * @param  array{name: string, description?: ?string, status?: ?string, start_date?: ?string, end_date?: ?string}  $data
     */
    public function update(Batch $batch, array $data, User $actor): Batch
    {
        $batch->fill([
            'name' => trim($data['name']),
            'description' => $this->clean($data['description'] ?? null),
        ]);

        foreach (self::DATE_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $batch->{$field} = $this->calendarDate($data[$field]);
            }
        }

        if (! $batch->isArchived() && isset($data['status'])) {
            $batch->status = $this->creatableStatus($data['status']);
        }

        if (! $batch->isDirty()) {
            return $batch;
        }

        [$old, $new] = array_map(fn (array $values) => $this->dateOnly($values), $this->audit->dirtyDiff($batch));
        $batch->updated_by = $actor->id;
        $batch->save();

        $this->audit->log(AuditAction::BatchUpdated, 'batches', $batch, "Batch {$batch->batch_number} updated", $old, $new);

        return $batch;
    }

    public function archive(Batch $batch, User $actor): Batch
    {
        if ($batch->isArchived()) {
            return $batch;
        }

        $old = $batch->status->value;
        $batch->forceFill(['status' => BatchStatus::Archived, 'updated_by' => $actor->id])->save();

        $this->audit->log(AuditAction::BatchArchived, 'batches', $batch, "Batch {$batch->batch_number} archived",
            ['status' => $old], ['status' => BatchStatus::Archived->value]);

        return $batch;
    }

    public function restore(Batch $batch, User $actor): Batch
    {
        if (! $batch->isArchived()) {
            return $batch;
        }

        $batch->forceFill(['status' => BatchStatus::Active, 'updated_by' => $actor->id])->save();

        $this->audit->log(AuditAction::BatchUpdated, 'batches', $batch, "Batch {$batch->batch_number} restored from archive",
            ['status' => BatchStatus::Archived->value], ['status' => BatchStatus::Active->value]);

        return $batch;
    }

    /** Removes the batch, its lead memberships and trainer assignments. Leads and users are never deleted. */
    public function delete(Batch $batch, User $actor): void
    {
        DB::transaction(function () use ($batch, $actor) {
            $memberships = DB::table('batch_leads')->where('batch_id', $batch->id)->delete();
            DB::table('batch_trainers')->where('batch_id', $batch->id)->delete();
            $batch->forceFill(['updated_by' => $actor->id])->save();
            $batch->delete();

            $this->audit->log(AuditAction::BatchDeleted, 'batches', $batch, "Batch {$batch->batch_number} deleted",
                null, ['batch_id' => $batch->id, 'lead_count' => $memberships]);
        });
    }

    /** @param array<int> $leadIds */
    public function addLeads(Batch $batch, array $leadIds, User $actor): BatchMembershipResult
    {
        $this->ensureOpen($batch);
        $leadIds = $this->visibleLeadIds($leadIds, $actor);

        return DB::transaction(function () use ($batch, $leadIds, $actor) {
            $locked = Batch::query()->whereKey($batch->id)->lockForUpdate()->first();
            if (! $locked) {
                throw ValidationException::withMessages(['lead_ids' => 'This batch no longer exists.']);
            }
            $this->ensureOpen($locked);

            return $this->insertMembers($batch, $leadIds, $actor);
        });
    }

    /** @param array<int> $leadIds */
    public function removeLeads(Batch $batch, array $leadIds, User $actor): BatchMembershipResult
    {
        $leadIds = $this->visibleLeadIds($leadIds, $actor);

        return DB::transaction(function () use ($batch, $leadIds) {
            $removed = DB::table('batch_leads')->where('batch_id', $batch->id)->whereIn('lead_id', $leadIds)->delete();
            $result = new BatchMembershipResult(count($leadIds), $removed);

            if ($removed > 0) {
                count($leadIds) === 1
                    ? $this->audit->log(AuditAction::BatchLeadRemoved, 'batches', $batch, "Lead removed from batch {$batch->batch_number}",
                        null, ['batch_id' => $batch->id, 'lead_id' => $leadIds[0]])
                    : $this->audit->log(AuditAction::BatchLeadsBulkRemoved, 'batches', $batch, "{$removed} leads removed from batch {$batch->batch_number}",
                        null, ['batch_id' => $batch->id, 'lead_count' => $removed]);
            }

            return $result;
        });
    }

    /**
     * Adds trainers; ones already assigned are skipped, so repeating a request is harmless.
     *
     * @param  array<int>  $trainerIds
     */
    public function assignTrainers(Batch $batch, array $trainerIds, User $actor): BatchMembershipResult
    {
        $this->ensureOpenForTrainers($batch);
        $ids = $this->eligibleTrainerIds($trainerIds);

        $added = DB::transaction(function () use ($batch, $ids, $actor) {
            $this->ensureOpenForTrainers($this->lockBatch($batch));

            return $this->insertTrainers($batch, $ids, $actor);
        });

        $this->notifyTrainers($batch, $added, $actor, assigned: true);

        return new BatchMembershipResult(count($ids), count($added));
    }

    /**
     * Removes trainer assignments only (also allowed on archived batches and
     * for deactivated users). The users, leads and the batch are untouched.
     *
     * @param  array<int>  $trainerIds
     */
    public function removeTrainers(Batch $batch, array $trainerIds, User $actor): BatchMembershipResult
    {
        $ids = $this->trainerIdList($trainerIds);

        $removed = DB::transaction(fn () => $this->deleteTrainers($this->lockBatch($batch), $ids));

        $this->notifyTrainers($batch, $removed, $actor, assigned: false);

        return new BatchMembershipResult(count($ids), count($removed));
    }

    /**
     * Makes the batch's trainers exactly `$trainerIds` (the edit form). Trainers
     * that stay assigned are kept even if they have since been deactivated;
     * only newly added ids must be eligible, and archived batches accept none.
     *
     * @param  array<int>  $trainerIds
     */
    public function syncTrainers(Batch $batch, array $trainerIds, User $actor): void
    {
        $ids = $this->trainerIdList($trainerIds);

        [$added, $removed] = DB::transaction(function () use ($batch, $ids, $actor) {
            $locked = $this->lockBatch($batch);
            $current = DB::table('batch_trainers')->where('batch_id', $batch->id)->pluck('trainer_id')->map(fn ($id) => (int) $id)->all();
            $toAdd = array_values(array_diff($ids, $current));
            $toRemove = array_values(array_diff($current, $ids));

            if ($toAdd !== []) {
                $this->ensureOpenForTrainers($locked);
                $this->eligibleTrainerIds($toAdd);
            }

            return [$this->insertTrainers($batch, $toAdd, $actor), $this->deleteTrainers($batch, $toRemove)];
        });

        $this->notifyTrainers($batch, $added, $actor, assigned: true);
        $this->notifyTrainers($batch, $removed, $actor, assigned: false);
    }

    /**
     * @param  array<int>  $trainerIds  already validated as eligible
     * @return array<int> ids that were newly assigned
     */
    private function insertTrainers(Batch $batch, array $trainerIds, User $actor): array
    {
        if ($trainerIds === []) {
            return [];
        }

        $existing = DB::table('batch_trainers')->where('batch_id', $batch->id)->whereIn('trainer_id', $trainerIds)->pluck('trainer_id')->map(fn ($id) => (int) $id)->all();
        $new = array_values(array_diff($trainerIds, $existing));
        if ($new === []) {
            return [];
        }

        $now = now();
        DB::table('batch_trainers')->insertOrIgnore(array_map(fn (int $id) => [
            'batch_id' => $batch->id,
            'trainer_id' => $id,
            'assigned_by' => $actor->id,
            'created_at' => $now,
        ], $new));

        count($new) === 1
            ? $this->audit->log(AuditAction::BatchTrainerAdded, 'batches', $batch, "Trainer added to batch {$batch->batch_number}",
                null, ['batch_id' => $batch->id, 'trainer_id' => $new[0]])
            : $this->audit->log(AuditAction::BatchTrainersBulkAdded, 'batches', $batch, count($new)." trainers added to batch {$batch->batch_number}",
                null, ['batch_id' => $batch->id, 'trainer_count' => count($new)]);

        return $new;
    }

    /**
     * @param  array<int>  $trainerIds
     * @return array<int> ids that were actually removed
     */
    private function deleteTrainers(Batch $batch, array $trainerIds): array
    {
        if ($trainerIds === []) {
            return [];
        }

        $removed = DB::table('batch_trainers')->where('batch_id', $batch->id)->whereIn('trainer_id', $trainerIds)->pluck('trainer_id')->map(fn ($id) => (int) $id)->all();
        if ($removed === []) {
            return [];
        }

        DB::table('batch_trainers')->where('batch_id', $batch->id)->whereIn('trainer_id', $removed)->delete();

        count($removed) === 1
            ? $this->audit->log(AuditAction::BatchTrainerRemoved, 'batches', $batch, "Trainer removed from batch {$batch->batch_number}",
                null, ['batch_id' => $batch->id, 'trainer_id' => $removed[0]])
            : $this->audit->log(AuditAction::BatchTrainersBulkRemoved, 'batches', $batch, count($removed)." trainers removed from batch {$batch->batch_number}",
                null, ['batch_id' => $batch->id, 'trainer_count' => count($removed)]);

        return $removed;
    }

    /** Sent after commit; a notification problem never undoes or fails the assignment. */
    private function notifyTrainers(Batch $batch, array $trainerIds, User $actor, bool $assigned): void
    {
        if ($trainerIds === []) {
            return;
        }

        foreach (User::query()->active()->whereIn('id', $trainerIds)->whereKeyNot($actor->id)->get() as $trainer) {
            try {
                $trainer->notify(new BatchTrainerNotification($batch, $assigned));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Rejects the whole request unless every id is an active Trainer;
     * `exists` validation alone would accept any user.
     *
     * @param  array<int|string>  $trainerIds
     * @return array<int>
     */
    private function eligibleTrainerIds(array $trainerIds): array
    {
        $ids = $this->trainerIdList($trainerIds);
        if ($ids === []) {
            return [];
        }

        if (User::query()->eligibleTrainer()->whereIn('id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['trainer_ids' => 'One or more selected users are not active trainers.']);
        }

        return $ids;
    }

    /**
     * @param  array<int|string>  $trainerIds
     * @return array<int>
     */
    private function trainerIdList(array $trainerIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $trainerIds), fn (int $id) => $id > 0)));

        if (count($ids) > self::MAX_TRAINERS_PER_REQUEST) {
            throw ValidationException::withMessages(['trainer_ids' => 'Select at most '.self::MAX_TRAINERS_PER_REQUEST.' trainers at a time.']);
        }

        return $ids;
    }

    private function lockBatch(Batch $batch): Batch
    {
        return Batch::query()->whereKey($batch->id)->lockForUpdate()->first()
            ?? throw ValidationException::withMessages(['trainer_ids' => 'This batch no longer exists.']);
    }

    private function ensureOpenForTrainers(Batch $batch): void
    {
        if ($batch->isArchived()) {
            throw ValidationException::withMessages(['trainer_ids' => 'This batch is archived and cannot receive new trainers.']);
        }
    }

    /**
     * One INSERT IGNORE per chunk: the UNIQUE(batch_id, lead_id) constraint
     * skips leads that are already members, including ones added by a
     * concurrent request, so nothing is duplicated and nothing throws.
     *
     * @param  array<int>  $leadIds
     */
    private function insertMembers(Batch $batch, array $leadIds, User $actor): BatchMembershipResult
    {
        $now = now();
        $added = 0;

        foreach (array_chunk($leadIds, self::INSERT_CHUNK) as $chunk) {
            $added += DB::table('batch_leads')->insertOrIgnore(array_map(fn (int $id) => [
                'batch_id' => $batch->id,
                'lead_id' => $id,
                'added_by' => $actor->id,
                'created_at' => $now,
            ], $chunk));
        }

        if ($added > 0) {
            count($leadIds) === 1
                ? $this->audit->log(AuditAction::BatchLeadAdded, 'batches', $batch, "Lead added to batch {$batch->batch_number}",
                    null, ['batch_id' => $batch->id, 'lead_id' => $leadIds[0]])
                : $this->audit->log(AuditAction::BatchLeadsBulkAdded, 'batches', $batch, "{$added} leads added to batch {$batch->batch_number}",
                    null, ['batch_id' => $batch->id, 'lead_count' => $added]);
        }

        return new BatchMembershipResult(count($leadIds), $added);
    }

    /**
     * Rejects the whole request unless every id is a live lead the actor may
     * see; `exists` validation alone would let hidden leads through.
     *
     * @param  array<int|string>  $leadIds
     * @return array<int>
     */
    private function visibleLeadIds(array $leadIds, User $actor): array
    {
        $ids = array_values(array_unique(array_map('intval', $leadIds)));
        if ($ids === []) {
            return [];
        }

        if (count($ids) > self::MAX_LEADS_PER_REQUEST) {
            throw ValidationException::withMessages(['lead_ids' => 'Select at most '.self::MAX_LEADS_PER_REQUEST.' leads at a time.']);
        }

        $visible = Lead::query()->visibleTo($actor)->whereIn('id', $ids)->pluck('id')->all();
        if (count($visible) !== count($ids)) {
            throw ValidationException::withMessages(['lead_ids' => 'One or more selected leads do not exist or are not available to you.']);
        }

        return $ids;
    }

    private function ensureOpen(Batch $batch): void
    {
        if ($batch->isArchived()) {
            throw ValidationException::withMessages(['lead_ids' => 'This batch is archived and cannot receive new leads.']);
        }
    }

    private function creatableStatus(?string $status): BatchStatus
    {
        $value = BatchStatus::tryFrom((string) $status);

        return $value === BatchStatus::Inactive ? BatchStatus::Inactive : BatchStatus::Active;
    }

    /** "2026-10-01" stays "2026-10-01": parsed as a plain calendar date, no timezone shift. */
    private function calendarDate(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : Carbon::parse($value)->toDateString();
    }

    /** Audit values for date columns as Y-m-d (the model stores them with a midnight time part). */
    private function dateOnly(array $values): array
    {
        foreach (self::DATE_FIELDS as $field) {
            if (isset($values[$field])) {
                $values[$field] = substr((string) $values[$field], 0, 10);
            }
        }

        return $values;
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
