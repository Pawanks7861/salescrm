<?php

namespace App\Console\Commands;

use App\Services\Maintenance\DemoDataCleaner;
use Illuminate\Console\Command;

/**
 * Pre-production / staging cleanup: empties every operational table and
 * removes demo accounts, keeping Super Admin(s) and system configuration.
 * For a first production deployment prefer a fresh database
 * (migrate + ProductionSeeder + crm:create-super-admin) instead.
 *
 * Refuses in production unless --force-production is given, and always
 * requires the phrase DELETE-DEMO-DATA to be typed (no --no-interaction bypass).
 */
class ClearDemoData extends Command
{
    public const CONFIRMATION = 'DELETE-DEMO-DATA';

    protected $signature = 'crm:clear-demo-data
        {--all-users : Also remove every user who is not a Super Admin (default: only demo accounts)}
        {--force-production : Allow running when APP_ENV=production (never needed for a fresh database)}';

    protected $description = 'Remove all demo/operational data and demo users, keeping Super Admin and system configuration (refuses in production)';

    public function handle(DemoDataCleaner $cleaner): int
    {
        if (app()->isProduction() && ! $this->option('force-production')) {
            $this->error('Refusing to run in production. This command deletes every lead, meeting and follow-up.');
            $this->line('For a new production CRM use a fresh database: php artisan migrate --force && php artisan db:seed --class=ProductionSeeder --force && php artisan crm:create-super-admin');

            return self::FAILURE;
        }

        $counts = $cleaner->counts();
        $this->warn('This permanently deletes the following rows (system configuration, roles, lookups, settings and branding are kept):');
        $this->table(['Table', 'Rows'], collect($counts)->map(fn ($n, $t) => [$t, $n])->values()->all());
        if ($this->option('all-users')) {
            $this->warn('--all-users: every account except Super Admin(s) will be removed.');
        }
        if (app()->isProduction()) {
            $this->error('APP_ENV is production. Only continue if this database holds no real business data.');
        }

        if ($this->ask('Type '.self::CONFIRMATION.' to continue') !== self::CONFIRMATION) {
            $this->info('Nothing deleted.');

            return self::FAILURE;
        }

        $result = $cleaner->clear((bool) $this->option('all-users'));

        $this->info(sprintf(
            'Deleted %d operational row(s), %d user account(s) and %d file(s). %d user account(s) remain.',
            array_sum($result['rows']), $result['users'], $result['files'], $result['kept_users'],
        ));
        $this->line('Verify with: php artisan crm:production-data-check');

        return self::SUCCESS;
    }
}
