<?php

namespace App\Http\Controllers\Batches;

use App\Http\Controllers\Controller;
use App\Http\Presenters\TrainerPresenter;
use App\Http\Requests\Batches\AssignBatchTrainersRequest;
use App\Http\Requests\Batches\RemoveBatchTrainersRequest;
use App\Models\Batch;
use App\Models\User;
use App\Services\Batches\BatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Trainer assignment from the batch page. All rules (eligibility, archived
 * batches, duplicates, audit, notifications) live in BatchService.
 */
class BatchTrainerController extends Controller
{
    private const SEARCH_LIMIT = 20;

    public function __construct(private readonly BatchService $batches) {}

    public function store(AssignBatchTrainersRequest $request, Batch $batch): RedirectResponse
    {
        $result = $this->batches->assignTrainers($batch, $request->trainerIds(), $request->user());

        $message = $result->changed === 1 ? "1 trainer added to {$batch->name}." : "{$result->changed} trainers added to {$batch->name}.";
        if ($result->unchanged() > 0) {
            $message .= " {$result->unchanged()} already assigned.";
        }

        return back()->with('success', $message);
    }

    public function destroy(Request $request, Batch $batch, User $trainer): RedirectResponse
    {
        $this->authorize('removeTrainers', $batch);

        $result = $this->batches->removeTrainers($batch, [$trainer->id], $request->user());

        return back()->with('success', $result->changed
            ? "{$trainer->name} removed from {$batch->name}. No leads or other batch data were changed."
            : "{$trainer->name} is not a trainer on {$batch->name}.");
    }

    public function bulkDestroy(RemoveBatchTrainersRequest $request, Batch $batch): RedirectResponse
    {
        $result = $this->batches->removeTrainers($batch, $request->trainerIds(), $request->user());

        return back()->with('success', ($result->changed === 1 ? '1 trainer' : "{$result->changed} trainers")." removed from {$batch->name}.");
    }

    /**
     * Active Trainers only, searched server-side and capped, for the trainer
     * pickers. `batch` marks the ones already on that batch.
     */
    public function search(Request $request): JsonResponse
    {
        $params = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'batch' => ['nullable', 'integer'],
        ]);
        $term = trim((string) ($params['q'] ?? ''));

        $trainers = User::query()->eligibleTrainer()
            ->when($term !== '', function ($q) use ($term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($q) => $q->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('employee_code', 'like', $like));
            })
            ->with('role:id,name,slug')
            ->orderBy('name')
            ->limit(self::SEARCH_LIMIT)
            ->get(TrainerPresenter::COLUMNS);

        $assigned = isset($params['batch'])
            ? DB::table('batch_trainers')->where('batch_id', (int) $params['batch'])->whereIn('trainer_id', $trainers->pluck('id'))->pluck('trainer_id')->flip()
            : collect();

        return response()->json([
            'results' => $trainers->map(fn (User $u) => [...TrainerPresenter::present($u), 'assigned' => $assigned->has($u->id)])->values(),
        ]);
    }
}
