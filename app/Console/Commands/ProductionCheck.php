<?php

namespace App\Console\Commands;

use App\Services\Notifications\PushEncryptionUnavailable;
use App\Support\CrmTime;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pre-go-live / post-deploy configuration check. Reports PASS / WARN / FAIL
 * and exits non-zero when anything FAILs. Never prints secret values: only
 * whether each credential is configured.
 */
class ProductionCheck extends Command
{
    public const SCHEDULER_HEARTBEAT_KEY = 'crm:scheduler:heartbeat';

    /** Accounts created by the local demo seeders or the non-production default seed. */
    public const DEMO_EMAIL_DOMAIN = '@salescrm.local';

    protected $signature = 'app:production-check';

    protected $description = 'Check that configuration, services and guards are ready for production (no secrets are displayed)';

    /** @var array<int, array{0: string, 1: string, 2: string}> */
    private array $results = [];

    public function handle(): int
    {
        $this->application();
        $this->database();
        $this->cache();
        $this->queue();
        $this->scheduler();
        $this->mail();
        $this->storage();
        $this->sessions();
        $this->logging();
        $this->meta();
        $this->telephony();
        $this->push();
        $this->demoData();

        $this->table(['Check', 'Status', 'Detail'], array_map(fn ($r) => [$r[0], $this->badge($r[1]), $r[2]], $this->results));

        $counts = array_count_values(array_column($this->results, 1));
        $fail = $counts['FAIL'] ?? 0;
        $warn = $counts['WARN'] ?? 0;
        $line = sprintf('%d passed, %d warning(s), %d failure(s).', $counts['PASS'] ?? 0, $warn, $fail);

        $fail > 0 ? $this->error($line.' Not ready for production.') : $this->info($line);

        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function application(): void
    {
        $env = (string) config('app.env');
        $this->record('APP_ENV', $env === 'production' ? 'PASS' : 'FAIL', "Environment is \"{$env}\"");
        $this->record('APP_DEBUG', config('app.debug') ? 'FAIL' : 'PASS', config('app.debug') ? 'Debug mode is ON (exposes internals)' : 'Debug mode is off');
        $this->record('APP_KEY', filled(config('app.key')) ? 'PASS' : 'FAIL', filled(config('app.key')) ? 'Configured' : 'Missing: php artisan key:generate');

        $url = (string) config('app.url');
        $this->record('HTTPS (APP_URL)', str_starts_with($url, 'https://') ? 'PASS' : 'FAIL', str_starts_with($url, 'https://') ? 'APP_URL uses https' : 'APP_URL must start with https://');

        $this->record('Config cache', app()->configurationIsCached() ? 'PASS' : 'WARN', app()->configurationIsCached() ? 'Cached' : 'Run php artisan config:cache after deploy');
        $this->record('Route cache', app()->routesAreCached() ? 'PASS' : 'WARN', app()->routesAreCached() ? 'Cached' : 'Run php artisan route:cache after deploy');

        $tz = (string) config('app.timezone');
        $this->record('Timezone', $tz === 'UTC' ? 'PASS' : 'FAIL', "Storage timezone {$tz}; CRM display timezone ".$this->safe(fn () => CrmTime::tz(), 'unknown'));
    }

    private function database(): void
    {
        try {
            DB::select('select 1');
        } catch (Throwable) {
            $this->record('Database', 'FAIL', 'Cannot connect (check DB_* settings)');

            return;
        }
        $this->record('Database', 'PASS', 'Connected ('.DB::connection()->getDriverName().')');

        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->record('Database engine', 'WARN', 'Production is documented for MySQL 8 / MariaDB');

            return;
        }

        $row = DB::selectOne('select @@session.sql_mode as mode, @@character_set_database as charset, @@session.time_zone as tz');
        $this->record('MySQL strict mode', str_contains((string) $row->mode, 'STRICT_TRANS_TABLES') ? 'PASS' : 'FAIL', str_contains((string) $row->mode, 'STRICT_TRANS_TABLES') ? 'STRICT_TRANS_TABLES active' : 'Strict mode is off');
        $this->record('MySQL charset', $row->charset === 'utf8mb4' ? 'PASS' : 'FAIL', "Database default charset {$row->charset}");
        $this->record('MySQL session timezone', in_array($row->tz, ['+00:00', 'UTC'], true) ? 'PASS' : 'WARN', "Session time_zone {$row->tz}");

        $pending = $this->safe(fn () => DB::table('migrations')->count() > 0, false);
        $this->record('Migrations', $pending ? 'PASS' : 'FAIL', $pending ? 'Migrations table present (run php artisan migrate --force on deploy)' : 'Migrations have not been run');
    }

    private function cache(): void
    {
        $store = (string) config('cache.default');
        if ($store === 'array' || $store === 'null') {
            $this->record('Cache', 'FAIL', "Cache store \"{$store}\" does not persist");

            return;
        }

        $key = 'crm:production-check:'.Str::random(8);
        $ok = $this->safe(function () use ($key) {
            Cache::put($key, 'ok', 30);
            $value = Cache::get($key);
            Cache::forget($key);

            return $value === 'ok';
        }, false);

        $this->record('Cache', $ok ? 'PASS' : 'FAIL', $ok ? "Store \"{$store}\" read/write OK" : "Store \"{$store}\" read/write failed");
    }

    private function queue(): void
    {
        $connection = (string) config('queue.default');
        if ($connection === 'sync') {
            $this->record('Queue', 'FAIL', 'QUEUE_CONNECTION=sync runs jobs inside web requests');

            return;
        }
        $this->record('Queue', 'PASS', "Connection \"{$connection}\"");

        if ($connection === 'database' && $this->safe(fn () => Schema::hasTable('jobs'), false)) {
            $oldest = DB::table('jobs')->whereNull('reserved_at')->min('available_at');
            $stale = $oldest !== null && (int) $oldest < now()->subMinutes(15)->getTimestamp();
            $this->record('Queue worker', $stale ? 'FAIL' : 'PASS', $stale ? 'Jobs waiting over 15 minutes: is the worker running?' : 'No stale pending jobs');
        }

        $failed = $this->safe(fn () => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0, 0);
        $this->record('Failed jobs', $failed > 0 ? 'WARN' : 'PASS', $failed > 0 ? "{$failed} failed job(s): php artisan queue:failed" : 'None');
    }

    private function scheduler(): void
    {
        $beat = $this->safe(fn () => Cache::get(self::SCHEDULER_HEARTBEAT_KEY), null);
        if (! $beat) {
            $this->record('Scheduler', 'WARN', 'No heartbeat yet: add cron "* * * * * php artisan schedule:run"');

            return;
        }

        $age = (int) Carbon::parse($beat)->diffInMinutes(now(), true);
        $this->record('Scheduler', $age <= 5 ? 'PASS' : 'FAIL', $age <= 5 ? 'Heartbeat '.$age.' min ago' : "Last heartbeat {$age} min ago: cron is not running");
    }

    private function mail(): void
    {
        $mailer = (string) config('mail.default');
        $this->record('Mail', in_array($mailer, ['log', 'array'], true) ? 'FAIL' : 'PASS', "Mailer \"{$mailer}\"".($mailer === 'smtp' ? (filled(config('mail.mailers.smtp.host')) ? ', host configured' : ', host missing') : ''));

        $from = (string) config('mail.from.address');
        $this->record('Mail from address', $from === '' || str_ends_with($from, '@example.com') ? 'WARN' : 'PASS', match (true) {
            $from === '' => 'MAIL_FROM_ADDRESS missing',
            str_ends_with($from, '@example.com') => 'MAIL_FROM_ADDRESS is still the example placeholder',
            default => 'Configured',
        });
    }

    private function storage(): void
    {
        $paths = ['storage/app/private', 'storage/app/public', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'];
        $notWritable = array_values(array_filter($paths, fn ($p) => ! is_writable(base_path($p))));

        $this->record('Storage writable', $notWritable === [] ? 'PASS' : 'FAIL', $notWritable === [] ? 'All runtime directories writable' : 'Not writable: '.implode(', ', $notWritable));
        $this->record('Private file disk', config('filesystems.disks.local.serve') ? 'FAIL' : 'PASS', config('filesystems.disks.local.serve') ? 'Local disk is served publicly' : 'Private files are not publicly served');
    }

    private function sessions(): void
    {
        $secure = (bool) config('session.secure');
        $this->record('Secure session cookie', $secure ? 'PASS' : 'FAIL', $secure ? 'SESSION_SECURE_COOKIE=true' : 'Set SESSION_SECURE_COOKIE=true (HTTPS only)');
        $this->record('Session cookie flags', config('session.http_only') && in_array(config('session.same_site'), ['lax', 'strict'], true) ? 'PASS' : 'FAIL', 'HttpOnly '.(config('session.http_only') ? 'on' : 'off').', SameSite '.(config('session.same_site') ?? 'none'));
        $this->record('Session driver', in_array(config('session.driver'), ['array', 'cookie'], true) ? 'WARN' : 'PASS', 'Driver "'.config('session.driver').'"');
    }

    private function logging(): void
    {
        $stack = (array) config('logging.channels.stack.channels', []);
        $channel = config('logging.default') === 'stack' ? ($stack[0] ?? 'single') : config('logging.default');
        $level = (string) config("logging.channels.{$channel}.level", 'debug');

        $this->record('Log level', $level === 'debug' ? 'WARN' : 'PASS', "LOG_LEVEL {$level}".($level === 'debug' ? ' (use warning or error in production)' : ''));
        $rotating = config('logging.default') === 'daily' || in_array('daily', $stack, true);
        $this->record('Log rotation', $rotating ? 'PASS' : 'WARN', $rotating ? 'Daily files (LOG_DAILY_DAYS retention)' : 'Use LOG_STACK=daily or rotate with logrotate');
    }

    private function meta(): void
    {
        $values = ['META_APP_ID' => config('meta.app_id'), 'META_APP_SECRET' => config('meta.app_secret'), 'META_WEBHOOK_VERIFY_TOKEN' => config('meta.webhook_verify_token')];
        $set = array_keys(array_filter($values, 'filled'));

        if ($set === []) {
            $this->record('Meta Lead Ads', 'WARN', 'Not configured (Facebook leads disabled)');
        } elseif (count($set) < count($values)) {
            $this->record('Meta Lead Ads', 'FAIL', 'Missing: '.implode(', ', array_diff(array_keys($values), $set)));
        } else {
            $this->record('Meta Lead Ads', 'PASS', 'App ID, secret and verify token configured');
        }

        $manual = (bool) config('meta.allow_manual_token');
        $this->record('Meta manual token form', $manual ? 'FAIL' : 'PASS', $manual ? 'META_ALLOW_MANUAL_TOKEN must be false' : 'Disabled');
    }

    private function telephony(): void
    {
        $driver = (string) config('telephony.driver');
        if ($driver === 'fake') {
            $this->record('Telephony driver', 'FAIL', 'TELEPHONY_DRIVER=fake is for local development only');

            return;
        }
        $this->record('Telephony driver', 'PASS', "Driver \"{$driver}\" (fake simulator disabled)");

        $values = [
            'EXOTEL_ACCOUNT_SID' => config('telephony.exotel.account_sid'),
            'EXOTEL_API_KEY' => config('telephony.exotel.api_key'),
            'EXOTEL_API_TOKEN' => config('telephony.exotel.api_token'),
            'EXOTEL_WEBHOOK_SECRET' => config('telephony.exotel.webhook_secret'),
        ];
        $set = array_keys(array_filter($values, 'filled'));

        if ($set === []) {
            $this->record('Exotel credentials', 'WARN', 'Not configured (calling disabled)');
        } elseif (count($set) < count($values)) {
            $this->record('Exotel credentials', 'FAIL', 'Missing: '.implode(', ', array_diff(array_keys($values), $set)));
        } else {
            $this->record('Exotel credentials', 'PASS', 'Account, API key/token and callback secret configured');
            $this->record('Exotel browser calling', filled(config('telephony.exotel.webrtc_access_token')) && filled(config('telephony.exotel.webrtc_sdk_url')) ? 'PASS' : 'WARN', filled(config('telephony.exotel.webrtc_sdk_url')) ? 'SDK URL configured' : 'Not configured (click-to-call only)');
        }
    }

    private function push(): void
    {
        $ok = filled(config('webpush.vapid.public_key')) && filled(config('webpush.vapid.private_key'));
        $this->record('Browser push (VAPID)', $ok ? 'PASS' : 'WARN', $ok ? 'Keys configured' : 'Not configured: php artisan webpush:vapid');

        if ($ok) {
            $encrypts = $this->safe(function () {
                PushEncryptionUnavailable::check();

                return true;
            }, false);
            $this->record('Browser push encryption', $encrypts ? 'PASS' : 'FAIL', $encrypts
                ? 'OpenSSL can create P-256 keys in this PHP process (the queue worker must use the same environment)'
                : 'OpenSSL cannot create P-256 keys: set OPENSSL_CONF for the queue worker, or every push fails');
        }
    }

    private function demoData(): void
    {
        $count = $this->safe(fn () => DB::table('users')->where('email', 'like', '%'.self::DEMO_EMAIL_DOMAIN)->count(), 0);
        $this->record('Demo accounts', $count > 0 ? 'FAIL' : 'PASS', $count > 0 ? $count.' demo/default account(s) (*'.self::DEMO_EMAIL_DOMAIN.') present' : 'None');
        $this->record('Demo seeders', app()->isProduction() ? 'PASS' : 'WARN', app()->isProduction() ? 'Refuse to run in production' : 'Would run in this environment');
    }

    private function record(string $check, string $status, string $detail): void
    {
        $this->results[] = [$check, $status, $detail];
    }

    private function badge(string $status): string
    {
        return match ($status) {
            'PASS' => '<fg=green>PASS</>',
            'WARN' => '<fg=yellow>WARN</>',
            default => '<fg=red>FAIL</>',
        };
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @param  T  $default
     * @return T
     */
    private function safe(callable $callback, mixed $default): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return $default;
        }
    }
}
