<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\BrandingService;
use App\Services\Chat\ConversationService;
use App\Services\Chat\PriorityBroadcastService;
use App\Services\Notifications\FcmService;
use App\Services\Notifications\WebPushService;
use App\Services\SettingService;
use App\Support\Navigation;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Only whitelisted, non-sensitive user attributes are shared with the browser.
     * The permission list drives UI visibility only; the backend re-checks everything.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();
        $settings = app(SettingService::class);

        return [
            ...parent::share($request),
            'app' => [
                'name' => fn () => $settings->get('general.crm_name'),
                'timezone' => fn () => $settings->get('general.timezone'),
                'company' => fn () => app(BrandingService::class)->companyName(),
                'logo_url' => fn () => app(BrandingService::class)->url('logo'),
                'favicon_url' => fn () => app(BrandingService::class)->faviconUrl(),
            ],
            'platform' => [
                'name' => config('crm.platform.name'),
                'url' => config('crm.platform.url'),
            ],
            'auth' => [
                'user' => $user ? fn () => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'designation' => $user->designation,
                    'role' => $user->role?->only('name', 'slug'),
                    'is_super_admin' => $user->isSuperAdmin(),
                ] : null,
                'permissions' => $user ? fn () => $user->permissionNames() : [],
            ],
            'navigation' => $user ? fn () => Navigation::for($user) : [],
            'push' => $user ? function () use ($user) {
                $push = app(WebPushService::class);
                $globally = $push->globallyEnabled();
                $vapid = $push->isConfigured() && $globally;
                $fcm = $globally ? app(FcmService::class)->webConfig() : null;

                return [
                    'available' => $vapid || $fcm !== null,
                    'public_key' => $vapid ? config('webpush.vapid.public_key') : null,
                    'fcm' => $fcm,
                    'sound_allowed' => $push->soundGloballyEnabled(),
                    'browser' => (bool) $user->browser_notifications_enabled,
                    'sound' => (bool) $user->notification_sound_enabled,
                ];
            } : null,
            'notifications' => [
                'unread' => $user ? fn () => $user->unreadNotifications()->count() : 0,
            ],
            'chat' => [
                'unread' => $user ? fn () => ($user->hasPermission(Permissions::CHAT_USE) ? app(ConversationService::class)->totalUnread($user->id) : 0) : 0,
            ],
            'priority' => $user ? fn () => app(PriorityBroadcastService::class)->activeFor($user) : [],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'download' => fn () => $request->session()->get('download'),
            ],
        ];
    }
}
