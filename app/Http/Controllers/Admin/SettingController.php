<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SettingsRequest;
use App\Services\BrandingService;
use App\Services\Security\OfficeNetworkGuard;
use App\Services\SettingService;
use App\Support\Permissions;
use App\Support\SettingDefinitions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingController extends Controller
{
    public function __construct(
        private readonly SettingService $settings,
        private readonly BrandingService $branding,
    ) {}

    public function index(Request $request, string $group = 'general'): Response
    {
        $fields = collect(SettingDefinitions::forGroup($group))
            ->map(fn ($definition, $key) => [
                'key' => $key,
                'name' => substr($key, strlen($group) + 1),
                'label' => $definition['label'],
                'type' => $definition['type'],
                'options' => $definition['options'] ?? null,
                'help' => $definition['help'] ?? null,
                'value' => $definition['type'] === 'encrypted' ? '' : $this->settings->get($key),
            ])
            ->values();

        return Inertia::render('Admin/Settings/Index', [
            'groups' => SettingDefinitions::GROUPS,
            'group' => $group,
            'fields' => $fields,
            'timezones' => $group === 'general' ? \DateTimeZone::listIdentifiers() : [],
            'branding' => $group === 'general' ? [
                'logo_url' => $this->branding->url('logo'),
                'favicon_url' => $this->branding->url('favicon'),
                'default_favicon_url' => $this->branding->faviconUrl(),
                'limits' => ['logo' => 'PNG, JPG or WEBP, up to 2 MB', 'favicon' => 'PNG, ICO or WEBP, up to 512 KB (square, 32×32 or 48×48)'],
            ] : null,
            'can' => ['manage' => $request->user()->hasPermission(Permissions::SETTINGS_MANAGE)],
            'version' => config('crm.version'),
            'network' => $group === 'security' ? [
                'client_ip' => $request->ip(),
                'restricted' => app(OfficeNetworkGuard::class)->enforced(),
                'allowed_here' => app(OfficeNetworkGuard::class)->allows($request->ip()),
            ] : null,
        ]);
    }

    public function update(SettingsRequest $request, string $group): RedirectResponse
    {
        $this->settings->updateGroup($group, $request->settingValues());

        return back()->with('success', SettingDefinitions::GROUPS[$group].' settings saved.');
    }
}
