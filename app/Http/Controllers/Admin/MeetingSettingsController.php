<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MeetingLocationMode;
use App\Http\Controllers\Controller;
use App\Models\MeetingType;
use App\Services\Meetings\MeetingTypeService;
use App\Services\SettingService;
use App\Support\LeadColors;
use App\Support\SettingDefinitions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Meeting types and meeting settings (route-gated by meeting.configure).
 * Settings are stored through the shared SettingService (group "meeting").
 * The default timezone stays the global general.timezone setting.
 */
class MeetingSettingsController extends Controller
{
    public function __construct(
        private readonly MeetingTypeService $types,
        private readonly SettingService $settings,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Admin/MeetingSettings/Index', [
            'types' => MeetingType::query()->ordered()
                ->withCount(['meetings' => fn ($q) => $q->withTrashed()])
                ->get(['id', 'name', 'slug', 'icon', 'color', 'location_mode', 'default_duration_minutes', 'sort_order', 'is_active', 'is_system']),
            'fields' => collect(SettingDefinitions::forGroup('meeting'))
                ->map(fn ($definition, $key) => [
                    'key' => $key,
                    'name' => substr($key, strlen('meeting.')),
                    'label' => $definition['label'],
                    'type' => $definition['type'],
                    'options' => $definition['options'] ?? null,
                    'value' => $this->settings->get($key),
                ])
                ->values(),
            'timezone' => $this->settings->get('general.timezone'),
            'colors' => LeadColors::ALL,
            'icons' => MeetingTypeService::ICONS,
            'locationModes' => MeetingLocationMode::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $type = $this->types->save(new MeetingType, $this->validated($request, null));

        return back()->with('success', "\"{$type->name}\" created.");
    }

    public function update(Request $request, MeetingType $meetingType): RedirectResponse
    {
        $this->types->save($meetingType, $this->validated($request, $meetingType));

        return back()->with('success', "\"{$meetingType->name}\" updated.");
    }

    public function destroy(MeetingType $meetingType): RedirectResponse
    {
        $this->types->delete($meetingType);

        return back()->with('success', "\"{$meetingType->name}\" deleted.");
    }

    public function reorder(Request $request): RedirectResponse
    {
        $ids = $request->validate([
            'ids' => ['required', 'array', 'max:100'],
            'ids.*' => ['integer', Rule::exists('meeting_types', 'id')],
        ])['ids'];

        $this->types->reorder($ids);

        return back()->with('success', 'Order saved.');
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $definitions = SettingDefinitions::forGroup('meeting');

        $rules = [];
        foreach ($definitions as $key => $definition) {
            $rules['settings.'.substr($key, strlen('meeting.'))] = $definition['rules'];
        }

        $validated = $request->validate($rules)['settings'] ?? [];

        $values = [];
        foreach ($validated as $name => $value) {
            $values['meeting.'.$name] = $value;
        }

        $this->settings->updateGroup('meeting', $values);

        return back()->with('success', 'Meeting settings saved.');
    }

    private function validated(Request $request, ?MeetingType $type): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('meeting_types', 'name')->ignore($type?->id)],
            'icon' => ['nullable', Rule::in(MeetingTypeService::ICONS)],
            'color' => ['required', Rule::in(LeadColors::ALL)],
            'location_mode' => ['required', Rule::enum(MeetingLocationMode::class)],
            'default_duration_minutes' => ['required', 'integer', 'min:5', 'max:480'],
            'is_active' => ['boolean'],
        ]);
    }
}
