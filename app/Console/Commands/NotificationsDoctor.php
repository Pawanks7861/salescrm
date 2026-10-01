<?php

namespace App\Console\Commands;

use App\Jobs\SendFcmNotification;
use App\Jobs\SendWebPushNotification;
use App\Models\FollowupReminder;
use App\Models\User;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\Followups\FollowupReminderNotification;
use App\Notifications\Leads\FacebookLeadNotification;
use App\Notifications\Leads\LeadAssignedNotification;
use App\Services\Notifications\FcmService;
use App\Services\Notifications\PushEncryptionUnavailable;
use App\Services\Notifications\WebPushService;
use App\Support\PushEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Checks each link of the notification chain separately (scheduler, reminder
 * claim, queue worker, push configuration and encryption, the user's opt-in
 * and subscriptions) and prints PASS / WARN / FAIL. Never prints endpoints,
 * keys, tokens or notification text.
 */
class NotificationsDoctor extends Command
{
    protected $signature = 'notifications:doctor
        {--user= : Email of a user whose browser notification setup should be checked}
        {--send-test= : Queue a real test push to that user: lead or reminder}';

    protected $description = 'Diagnose follow-up reminder and browser notification delivery (no secrets are displayed)';

    /** @var array<int, array{0: string, 1: string, 2: string}> */
    private array $rows = [];

    public function handle(WebPushService $push): int
    {
        $this->scheduler();
        $this->reminders();
        $this->worker();
        $this->pushConfig($push);

        $user = null;
        if ($email = $this->option('user')) {
            $user = User::query()->where('email', $email)->first();
            $user ? $this->user($user, $push) : $this->record('User', 'FAIL', 'No user with that email');
        }

        $this->table(['Link', 'Status', 'Detail'], array_map(fn ($r) => [$r[0], match ($r[1]) {
            'PASS' => '<fg=green>PASS</>', 'WARN' => '<fg=yellow>WARN</>', default => '<fg=red>FAIL</>',
        }, $r[2]], $this->rows));

        if ($type = $this->option('send-test')) {
            return $this->sendTest($user, (string) $type, $push);
        }

        return in_array('FAIL', array_column($this->rows, 1), true) ? self::FAILURE : self::SUCCESS;
    }

    private function scheduler(): void
    {
        $beat = Cache::get(ProductionCheck::SCHEDULER_HEARTBEAT_KEY);
        if (! $beat) {
            $this->record('Scheduler', 'FAIL', 'No heartbeat: run "php artisan schedule:work" (dev) or the schedule:run cron');

            return;
        }
        $age = (int) Carbon::parse($beat)->diffInMinutes(now(), true);
        $this->record('Scheduler', $age <= 2 ? 'PASS' : 'FAIL', $age <= 2 ? "Heartbeat {$age} min ago" : "Last heartbeat {$age} min ago: the scheduler is not running");
    }

    private function reminders(): void
    {
        $unclaimed = FollowupReminder::query()->where('status', FollowupReminder::PENDING)
            ->where('remind_at', '<=', now()->subMinutes(2))->count();
        $this->record('Reminder claim', $unclaimed === 0 ? 'PASS' : 'FAIL', $unclaimed === 0
            ? 'No due reminders waiting to be claimed'
            : "{$unclaimed} due reminder(s) not claimed: followups:dispatch-reminders is not running");

        $stuck = FollowupReminder::query()->where('status', FollowupReminder::PROCESSING)
            ->where('claimed_at', '<', now()->subMinutes(2))->count();
        $this->record('Reminder delivery', $stuck === 0 ? 'PASS' : 'FAIL', $stuck === 0
            ? 'No claimed reminders waiting for the queue'
            : "{$stuck} claimed reminder(s) not delivered: is the queue worker running?");

        $failed = FollowupReminder::query()->where('status', FollowupReminder::FAILED)
            ->where('updated_at', '>=', now()->subDay())->count();
        $this->record('Reminder failures (24 h)', $failed === 0 ? 'PASS' : 'WARN', $failed === 0 ? 'None' : "{$failed} reminder(s) failed after retries (see last_error)");
    }

    private function worker(): void
    {
        if (config('queue.default') !== 'database') {
            $this->record('Queue worker', 'WARN', 'Connection "'.config('queue.default').'": backlog not inspected');

            return;
        }
        $oldest = DB::table('jobs')->whereNull('reserved_at')->where('available_at', '<=', now()->getTimestamp())->min('available_at');
        $late = $oldest !== null && (int) $oldest < now()->subMinutes(2)->getTimestamp();
        $this->record('Queue worker', $late ? 'FAIL' : 'PASS', $late
            ? 'Jobs waiting over 2 minutes: start "php artisan queue:work --queue=default,integrations"'
            : 'No jobs waiting');

        $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
        $this->record('Failed jobs (24 h)', $failed === 0 ? 'PASS' : 'WARN', $failed === 0 ? 'None' : "{$failed}: php artisan queue:failed");
    }

    private function pushConfig(WebPushService $push): void
    {
        $this->record('VAPID keys', $push->isConfigured() ? 'PASS' : 'FAIL', $push->isConfigured() ? 'Configured' : 'Missing: php artisan webpush:vapid');
        $this->record('Admin switch: browser', $push->globallyEnabled() ? 'PASS' : 'WARN', $push->globallyEnabled() ? 'On' : 'Off (System Settings → Notifications)');
        $this->record('Admin switch: sound', $push->soundGloballyEnabled() ? 'PASS' : 'WARN', $push->soundGloballyEnabled() ? 'On' : 'Off (System Settings → Notifications)');
        $fcm = app(FcmService::class);
        $this->record('FCM', $fcm->isConfigured() ? 'PASS' : 'WARN', $fcm->isConfigured() ? 'Configured' : 'Not configured (optional)');

        try {
            PushEncryptionUnavailable::check();
            $this->record('Push encryption', 'PASS', 'OpenSSL can create P-256 keys in this process'.(getenv('OPENSSL_CONF') ? ' (OPENSSL_CONF set)' : ''));
        } catch (Throwable) {
            $this->record('Push encryption', 'FAIL', 'OpenSSL cannot create P-256 keys: set OPENSSL_CONF for the queue worker'
                .(PHP_OS_FAMILY === 'Windows' ? ' (e.g. '.dirname(PHP_BINARY).'\\extras\\ssl\\openssl.cnf)' : ''));
        }
    }

    private function user(User $user, WebPushService $push): void
    {
        $this->record('User active', $user->is_active ? 'PASS' : 'FAIL', $user->is_active ? 'Active' : 'Inactive users receive nothing');
        $this->record('User: browser notifications', $user->browser_notifications_enabled ? 'PASS' : 'WARN', $user->browser_notifications_enabled ? 'On' : 'Off in My profile → Notification preferences');
        $this->record('User: sound', $user->notification_sound_enabled ? 'PASS' : 'WARN', $user->notification_sound_enabled ? 'On' : 'Off');

        $subs = $user->pushSubscriptions()->get(['id', 'user_agent', 'last_used_at']);
        $this->record('User: subscribed browsers', $subs->isNotEmpty() ? 'PASS' : 'FAIL', $subs->isEmpty()
            ? 'None: click Enable in the browser that should receive notifications'
            : $subs->map(fn ($s) => '#'.$s->id.' '.Str::limit((string) $s->user_agent, 40).' (last push '.($s->last_used_at?->diffForHumans() ?? 'never').')')->implode('; '));
        $this->record('User: push will be sent', $push->shouldPush($user) ? 'PASS' : 'FAIL', $push->shouldPush($user) ? 'All conditions met' : 'Not all conditions above are met');

        if (app(FcmService::class)->isConfigured()) {
            $devices = $user->fcmTokens()->count();
            $this->record('User: FCM devices', $devices > 0 ? 'PASS' : 'WARN', $devices > 0 ? "{$devices} registered device(s)" : 'No device has registered');
        }

        $recent = $user->notifications()
            ->whereIn('type', [FollowupReminderNotification::class, LeadAssignedNotification::class, FacebookLeadNotification::class])
            ->where('created_at', '>=', now()->subDay())->count();
        $this->record('User: in-app notifications (24 h)', 'PASS', "{$recent} new-lead / reminder notification(s)");
    }

    private function sendTest(?User $user, string $type, WebPushService $push): int
    {
        if (! $user || ! in_array($type, ['lead', 'reminder'], true)) {
            $this->error('--send-test needs --user and must be "lead" or "reminder".');

            return self::FAILURE;
        }
        $fcm = app(FcmService::class);
        $web = $push->shouldPush($user);
        $firebase = $fcm->shouldSend($user);
        if (! $web && ! $firebase) {
            $this->error('This user cannot receive pushes yet (see the rows above).');

            return self::FAILURE;
        }

        $lead = $type === 'lead';
        $payload = WebPushChannel::payload(
            'test-'.Str::uuid(),
            $lead ? PushEvent::NEW_LEAD_ASSIGNED : PushEvent::FOLLOWUP_REMINDER,
            $lead ? 'New Lead Assigned' : 'Follow-up Reminder',
            $lead ? 'Test: a new lead has been assigned to you.' : 'Test: your follow-up is due now.',
            route('profile.edit', [], false),
        );
        if ($web) {
            SendWebPushNotification::dispatch($user->id, $payload);
        }
        if ($firebase) {
            SendFcmNotification::dispatch($user->id, $payload);
        }
        $this->info('Test push queued; the queue worker sends it.');

        return self::SUCCESS;
    }

    private function record(string $link, string $status, string $detail): void
    {
        $this->rows[] = [$link, $status, $detail];
    }
}
