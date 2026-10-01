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
use Throwable;

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
                'name' => $this->guard(fn () => $settings->get('general.crm_name'), 'Sales CRM'),
                'timezone' => $this->guard(fn () => $settings->get('general.timezone'), 'UTC'),
                'company' => $this->guard(fn () => app(BrandingService::class)->companyName(), 'Sales CRM'),
                'logo_url' => $this->guard(fn () => app(BrandingService::class)->url('logo'), null),
                'favicon_url' => $this->guard(fn () => app(BrandingService::class)->faviconUrl(), null),
            ],
            'platform' => [
                'name' => config('crm.platform.name'),
                'url' => config('crm.platform.url'),
            ],
            'auth' => [
                'user' => $user ? $this->guard(fn () => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'designation' => $user->designation,
                    'role' => $user->role?->only('name', 'slug'),
                    'is_super_admin' => $user->isSuperAdmin(),
                ], null) : null,
                'permissions' => $user ? $this->guard(fn () => $user->permissionNames(), []) : [],
            ],
            'navigation' => $user ? $this->guard(fn () => Navigation::for($user), []) : [],
            'push' => $user ? $this->guard(function () use ($user) {
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
            }, null) : null,
            'notifications' => [
                'unread' => $user ? $this->guard(fn () => $user->unreadNotifications()->count(), 0) : 0,
            ],
            'chat' => [
                'unread' => $user ? fn () => ($user->hasPermission(Permissions::CHAT_USE) ? app(ConversationService::class)->totalUnread($user->id) : 0) : 0,
            ],
            'priority' => $user ? fn () => app(PriorityBroadcastService::class)->activeFor($user) : [],
            'flash' => [
                'success' => $this->guard(fn () => $request->session()->get('success'), null),
                'error' => $this->guard(fn () => $request->session()->get('error'), null),
                'download' => $this->guard(fn () => $request->session()->get('download'), null),
            ],
        ];
    }

    /**
     * A shared prop must not take down every signed-in page. The failure is
     * logged; the screen still opens with a safe fallback.
     */
    private function guard(callable $resolve, mixed $fallback): callable
    {
        return function () use ($resolve, $fallback) {
            try {
                return $resolve();
            } catch (Throwable $e) {
                report($e);

                return $fallback;
            }
        };
    }
}
