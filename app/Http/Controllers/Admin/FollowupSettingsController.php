<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FollowupType;
use App\Services\Followups\FollowupTypeService;
use App\Services\SettingService;
use App\Support\LeadColors;
use App\Support\SettingDefinitions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Follow-up types and follow-up settings (route-gated by followup.configure).
 * Settings are stored through the shared SettingService (group "followup").
 */
class FollowupSettingsController extends Controller
{
    public function __construct(
        private readonly FollowupTypeService $types,
        private readonly SettingService $settings,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Admin/FollowupSettings/Index', [
            'types' => FollowupType::query()->ordered()
                ->withCount(['followups' => fn ($q) => $q->withTrashed()])
                ->get(['id', 'name', 'slug', 'icon', 'color', 'sort_order', 'is_active', 'is_system']),
            'fields' => collect(SettingDefinitions::forGroup('followup'))
                ->map(fn ($definition, $key) => [
                    'key' => $key,
                    'name' => substr($key, strlen('followup.')),
                    'label' => $definition['label'],
                    'type' => $definition['type'],
                    'options' => $definition['options'] ?? null,
                    'value' => $this->settings->get($key),
                ])
                ->values(),
            'colors' => LeadColors::ALL,
            'icons' => FollowupTypeService::ICONS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $type = $this->types->save(new FollowupType, $this->validated($request, null));

        return back()->with('success', "\"{$type->name}\" created.");
    }

    public function update(Request $request, FollowupType $followupType): RedirectResponse
    {
        $this->types->save($followupType, $this->validated($request, $followupType));

        return back()->with('success', "\"{$followupType->name}\" updated.");
    }

    public function destroy(FollowupType $followupType): RedirectResponse
    {
        $this->types->delete($followupType);

        return back()->with('success', "\"{$followupType->name}\" deleted.");
    }

    public function reorder(Request $request): RedirectResponse
    {
        $ids = $request->validate([
            'ids' => ['required', 'array', 'max:100'],
            'ids.*' => ['integer', Rule::exists('followup_types', 'id')],
        ])['ids'];

        $this->types->reorder($ids);

        return back()->with('success', 'Order saved.');
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $definitions = SettingDefinitions::forGroup('followup');

        $rules = [];
        foreach ($definitions as $key => $definition) {
            $rules['settings.'.substr($key, strlen('followup.'))] = $definition['rules'];
        }

        $validated = $request->validate($rules)['settings'] ?? [];

        $values = [];
        foreach ($validated as $name => $value) {
            $values['followup.'.$name] = $value;
        }

        $this->settings->updateGroup('followup', $values);

        return back()->with('success', 'Follow-up settings saved.');
    }

    private function validated(Request $request, ?FollowupType $type): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('followup_types', 'name')->ignore($type?->id)],
            'icon' => ['nullable', Rule::in(FollowupTypeService::ICONS)],
            'color' => ['required', Rule::in(LeadColors::ALL)],
            'is_active' => ['boolean'],
        ]);
    }
}
