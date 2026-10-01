<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SettingsRequest;
use App\Jobs\SendFcmNotification;
use App\Jobs\SendWebPushNotification;
use App\Models\User;
use App\Notifications\AdminAlertNotification;
use App\Notifications\Channels\WebPushChannel;
use App\Services\AuditService;
use App\Services\BrandingService;
use App\Services\Notifications\FcmService;
use App\Services\Notifications\WebPushService;
use App\Services\Security\OfficeNetworkGuard;
use App\Services\SettingService;
use App\Support\Permissions;
use App\Support\SettingDefinitions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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

    /**
     * Writes an in-app alert for every active user during this request, and
     * queues browser/FCM delivery. Push is not sent inline: a slow provider
     * call was failing the whole page with a 500.
     */
    public function alertEveryone(Request $request, AuditService $audit, WebPushService $push, FcmService $fcm): RedirectResponse
    {
        $count = 0;

        try {
            User::query()->active()->orderBy('id')->each(function (User $user) use ($push, $fcm, &$count) {
                try {
                    $notification = new AdminAlertNotification;
                    $user->notify($notification);
                    $count++;

                    $payload = WebPushChannel::payload(
                        (string) $notification->id,
                        'ADMIN_ALERT',
                        AdminAlertNotification::TITLE,
                        AdminAlertNotification::BODY,
                        '/notifications',
                    );

                    if ($push->shouldPush($user)) {
                        SendWebPushNotification::dispatch($user->id, $payload);
                    }
                    if ($fcm->shouldSend($user)) {
                        SendFcmNotification::dispatch($user->id, $payload);
                    }
                } catch (\Throwable $e) {
                    Log::warning('Live alert failed for one user', [
                        'user_id' => $user->id,
                        'error' => class_basename($e),
                    ]);
                }
            });
        } catch (\Throwable $e) {
            Log::warning('Live alert failed', ['error' => class_basename($e)]);

            return back()->with('error', 'The alert could not be sent. Please try again.');
        }

        if ($count === 0) {
            return back()->with('error', 'No active users could be alerted.');
        }

        try {
            $audit->log(
                AuditAction::NotificationBroadcast,
                'settings',
                null,
                "{$request->user()->name} sent a live alert to {$count} users",
                null,
                ['users' => $count],
            );
        } catch (\Throwable $e) {
            Log::warning('Live alert audit failed', ['error' => class_basename($e)]);
        }

        return back()->with('success', "Alert sent to {$count} active users. It is in their notification list now.");
    }
}
