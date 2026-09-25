<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\LostReason;
use App\Services\Leads\LeadConfigurationService;
use App\Support\LeadColors;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Statuses, sources, lost reasons and campaigns (route-gated by lead.configure). */
class LeadSettingsController extends Controller
{
    public const TYPES = ['statuses', 'sources', 'lost-reasons', 'campaigns'];

    public function __construct(private readonly LeadConfigurationService $config) {}

    public function index(string $type = 'statuses'): Response
    {
        $items = match ($type) {
            'statuses' => LeadStatus::query()->ordered()->withCount(['leads' => fn ($q) => $q->withTrashed()])->get(),
            'sources' => LeadSource::query()->ordered()->withCount(['leads' => fn ($q) => $q->withTrashed()])->get(),
            'lost-reasons' => LostReason::query()->ordered()->withCount(['leads' => fn ($q) => $q->withTrashed()])->get(),
            'campaigns' => Campaign::query()->with('source:id,name')->withCount(['leads' => fn ($q) => $q->withTrashed()])->orderByDesc('is_active')->orderBy('name')->get(),
        };

        return Inertia::render('Admin/LeadSettings/Index', [
            'type' => $type,
            'items' => $items,
            'colors' => LeadColors::ALL,
            'sources' => LeadSource::query()->ordered()->get(['id', 'name']),
            'platforms' => Campaign::PLATFORMS,
        ]);
    }

    public function store(Request $request, string $type): RedirectResponse
    {
        [$data, $explicit] = $this->validated($request, $type, null);
        $model = $this->config->save($this->newModel($type), $data, $explicit);

        return back()->with('success', "\"{$model->name}\" created.");
    }

    public function update(Request $request, string $type, int $id): RedirectResponse
    {
        $model = $this->find($type, $id);
        [$data, $explicit] = $this->validated($request, $type, $model);
        $this->config->save($model, $data, $explicit);

        return back()->with('success', "\"{$model->name}\" updated.");
    }

    public function destroy(string $type, int $id): RedirectResponse
    {
        $model = $this->find($type, $id);
        $this->config->delete($model);

        return back()->with('success', "\"{$model->name}\" deleted.");
    }

    public function reorder(Request $request, string $type): RedirectResponse
    {
        abort_if($type === 'campaigns', 404);

        $class = LeadConfigurationService::TYPES[$type];
        $ids = $request->validate(['ids' => ['required', 'array', 'max:200'], 'ids.*' => ['integer', Rule::exists((new $class)->getTable(), 'id')]])['ids'];
        $this->config->reorder($class, $ids);

        return back()->with('success', 'Order saved.');
    }

    /** @return array{0: array, 1: array} [fillable data, explicitly-set attributes] */
    private function validated(Request $request, string $type, ?Model $model): array
    {
        $table = $this->newModel($type)->getTable();
        $unique = Rule::unique($table, 'name')->ignore($model?->getKey());

        if ($type === 'statuses') {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:60', $unique],
                'description' => ['nullable', 'string', 'max:255'],
                'color' => ['required', Rule::in(LeadColors::ALL)],
                'probability' => ['required', 'integer', 'between:0,100'],
                'is_won' => ['boolean'],
                'is_lost' => ['boolean'],
                'is_active' => ['boolean'],
                'is_default' => ['boolean'],
                'sort_order' => ['nullable', 'integer', 'between:0,65000'],
            ]);

            if (($data['is_won'] ?? false) && ($data['is_lost'] ?? false)) {
                throw ValidationException::withMessages(['is_won' => 'A status cannot be both won and lost.']);
            }
            if (($data['is_default'] ?? false) && (($data['is_won'] ?? false) || ($data['is_lost'] ?? false) || ! ($data['is_active'] ?? true))) {
                throw ValidationException::withMessages(['is_default' => 'The default status must be active and cannot be won or lost.']);
            }
            if ($model?->is_default && ! ($data['is_default'] ?? false)) {
                throw ValidationException::withMessages(['is_default' => 'Choose another default status first.']);
            }
            if ($model && ! ($data['is_active'] ?? true) && Lead::where('status_id', $model->id)->exists()) {
                throw ValidationException::withMessages(['is_active' => 'Move leads out of this status before deactivating it.']);
            }

            $explicit = ['is_default' => (bool) ($data['is_default'] ?? false)];
            unset($data['is_default']);
            $data['sort_order'] ??= $model?->sort_order ?? ((int) LeadStatus::max('sort_order') + 10);

            return [$data, $explicit];
        }

        if ($type === 'sources') {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:60', $unique],
                'description' => ['nullable', 'string', 'max:255'],
                'color' => ['required', Rule::in(LeadColors::ALL)],
                'is_active' => ['boolean'],
                'is_default' => ['boolean'],
                'sort_order' => ['nullable', 'integer', 'between:0,65000'],
            ]);

            if ($model?->is_default && ! ($data['is_default'] ?? false)) {
                throw ValidationException::withMessages(['is_default' => 'Choose another default source first.']);
            }

            $explicit = ['is_default' => (bool) ($data['is_default'] ?? false)];
            unset($data['is_default']);
            $data['sort_order'] ??= $model?->sort_order ?? ((int) LeadSource::max('sort_order') + 10);

            return [$data, $explicit];
        }

        if ($type === 'lost-reasons') {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:100', $unique],
                'is_active' => ['boolean'],
                'sort_order' => ['nullable', 'integer', 'between:0,65000'],
            ]);
            $data['sort_order'] ??= $model?->sort_order ?? ((int) LostReason::max('sort_order') + 10);

            return [$data, []];
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'source_id' => ['nullable', 'integer', Rule::exists('lead_sources', 'id')],
            'platform' => ['required', Rule::in(Campaign::PLATFORMS)],
            'external_id' => ['nullable', 'string', 'max:100', Rule::unique('campaigns')->where('platform', $request->input('platform'))->ignore($model?->getKey())],
            'description' => ['nullable', 'string', 'max:500'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['boolean'],
        ]);

        return [$data, []];
    }

    private function newModel(string $type): Model
    {
        $class = LeadConfigurationService::TYPES[$type] ?? abort(404);

        return new $class;
    }

    private function find(string $type, int $id): Model
    {
        return $this->newModel($type)->newQuery()->findOrFail($id);
    }
}
