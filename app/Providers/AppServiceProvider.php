<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Followups\FollowupVisibility;
use App\Services\Leads\LeadVisibility;
use App\Services\Meetings\MeetingVisibility;
use App\Services\Notifications\MinishlinkPushTransport;
use App\Services\Notifications\PushTransport;
use App\Services\PermissionRegistrar;
use App\Services\SettingService;
use App\Services\Telephony\CallVisibility;
use App\Services\Telephony\TelephonyManager;
use App\Support\Permissions;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PermissionRegistrar::class);
        $this->app->scoped(SettingService::class);
        $this->app->scoped(LeadVisibility::class);
        $this->app->scoped(FollowupVisibility::class);
        $this->app->scoped(MeetingVisibility::class);
        $this->app->scoped(CallVisibility::class);
        $this->app->singleton(TelephonyManager::class);
        $this->app->bind(PushTransport::class, MinishlinkPushTransport::class);
    }

    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        if ($proxies = config('app.trusted_proxies')) {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
        if ($this->app->isProduction() && str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        Route::patterns(['lead' => '[0-9]+', 'note' => '[0-9]+', 'attachment' => '[0-9]+', 'followup' => '[0-9]+', 'followupType' => '[0-9]+', 'meeting' => '[0-9]+', 'meetingType' => '[0-9]+', 'participant' => '[0-9]+', 'facebookPage' => '[0-9]+', 'facebookForm' => '[0-9]+', 'facebookEvent' => '[0-9]+', 'call' => '[0-9]+', 'telephonyNumber' => '[0-9]+', 'telephonyUser' => '[0-9]+', 'callDisposition' => '[0-9]+']);

        $this->registerAuthorization();
        $this->registerRateLimiters();

        Password::defaults(function () {
            $min = (int) app(SettingService::class)->get('security.password_min_length', 12);

            return Password::min($min)->letters()->mixedCase()->numbers();
        });
    }

    private function registerAuthorization(): void
    {
        // Bypass applies to permission abilities only; policy methods still enforce
        // structural rules (e.g. Super Admin role is immutable, no self-deactivation).
        Gate::before(fn (User $user, string $ability) => $user->isSuperAdmin() && str_contains($ability, '.') ? true : null);

        foreach (Permissions::names() as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }
    }

    private function registerRateLimiters(): void
    {
        RateLimiter::for('crm', fn (Request $request) => Limit::perMinute(300)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('sensitive', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('search', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));
        // Meta batches deliveries; this only caps abusive floods per source IP.
        RateLimiter::for('meta-webhook', fn (Request $request) => Limit::perMinute(1200)->by($request->ip()));
        // Exotel stays under 200 API req/min per account; callbacks are far fewer than this cap.
        RateLimiter::for('telephony-webhook', fn (Request $request) => Limit::perMinute(600)->by($request->ip()));
        // Softphone session / call initiation per user.
        RateLimiter::for('telephony', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));
        // Uptime monitors poll every 30–60 s.
        RateLimiter::for('health', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
    }
}
