<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Maintenance\DemoDataCleaner;
use App\Support\DemoData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Counts only: never prints names, emails, phone numbers or credentials.
 *
 * Default (pre-go-live) mode fails on any operational row. With --live it
 * only fails on demo markers, for use after real data exists.
 */
class ProductionDataCheck extends Command
{
    protected $signature = 'crm:production-data-check
        {--live : The CRM is live: allow business records, fail only on demo/test markers}';

    protected $description = 'Verify the database holds system configuration and one Super Admin only (counts only, no personal data)';

    public const SYSTEM_TABLES = [
        'Roles' => 'roles',
        'Permissions' => 'permissions',
        'Lead statuses' => 'lead_statuses',
        'Lead sources' => 'lead_sources',
        'Lost reasons' => 'lost_reasons',
        'Follow-up types' => 'followup_types',
        'Meeting types' => 'meeting_types',
        'Settings' => 'settings',
    ];

    public const OPERATIONAL_LABELS = [
        'Leads' => 'leads',
        'Lead enquiries' => 'lead_enquiries',
        'Lead notes' => 'lead_notes',
        'Lead assignments' => 'lead_assignments',
        'Lead status changes' => 'lead_status_changes',
        'Follow-ups' => 'followups',
        'Follow-up reminders' => 'followup_reminders',
        'Meetings' => 'meetings',
        'Meta integrations' => 'facebook_integrations',
        'Meta pages' => 'facebook_pages',
        'Meta forms' => 'facebook_forms',
        'Meta webhook events' => 'facebook_webhook_events',
        'Campaigns' => 'campaigns',
        'Assignment rules' => 'lead_assignment_rules',
        'Teams (legacy)' => 'teams',
        'Activities' => 'activities',
        'Attachments' => 'attachments',
        'Notifications' => 'notifications',
        'Push subscriptions' => 'push_subscriptions',
        'Report exports' => 'report_exports',
        'Login history' => 'login_histories',
        'Sessions' => 'sessions',
        'Audit log' => 'audit_logs',
        'Number sequences' => 'number_sequences',
        'Queued jobs' => 'jobs',
        'Failed jobs' => 'failed_jobs',
    ];

    /** @var array<int, array{0: string, 1: string, 2: string}> */
    private array $rows = [];

    public function handle(): int
    {
        $live = (bool) $this->option('live');

        $this->users();
        foreach (self::SYSTEM_TABLES as $label => $table) {
            $n = $this->count($table);
            $this->record($label, $n > 0 ? 'PASS' : 'FAIL', (string) $n);
        }
        foreach (self::OPERATIONAL_LABELS as $label => $table) {
            $n = $this->count($table);
            $this->record($label, $n === 0 || $live ? 'PASS' : 'FAIL', (string) $n);
        }
        $files = $this->operationalFiles();
        $this->record('Operational files', $files === 0 || $live ? 'PASS' : 'FAIL', (string) $files);
        $this->demoMarkers();

        $this->table(['Check', 'Status', 'Count'], array_map(fn ($r) => [$r[0], match ($r[1]) {
            'PASS' => '<fg=green>PASS</>', 'WARN' => '<fg=yellow>WARN</>', default => '<fg=red>FAIL</>',
        }, $r[2]], $this->rows));

        $failed = count(array_filter($this->rows, fn ($r) => $r[1] === 'FAIL'));
        if ($failed > 0) {
            $this->error("{$failed} check(s) failed.");

            return self::FAILURE;
        }
        $this->info($live ? 'No demo or test data found.' : 'Clean: system configuration and Super Admin only.');

        return self::SUCCESS;
    }

    private function users(): void
    {
        $users = User::withTrashed()->count();
        $superAdmins = User::whereHas('role', fn ($q) => $q->where('slug', User::SUPER_ADMIN_ROLE));
        $active = (clone $superAdmins)->where('is_active', true)->count();
        $total = $superAdmins->count();

        $this->record('Users', $users === 1 || $this->option('live') ? 'PASS' : 'FAIL', (string) $users);
        $this->record('Super Admins', $total === 1 ? 'PASS' : ($total === 0 ? 'FAIL' : 'WARN'), (string) $total);
        $this->record('Active Super Admins', $active > 0 ? 'PASS' : 'FAIL', (string) $active);
    }

    private function demoMarkers(): void
    {
        $demoUsers = User::withTrashed()->where(fn ($q) => $q
            ->whereIn('email', DemoData::USER_EMAILS)
            ->orWhere('email', 'like', '%'.DemoData::EMAIL_DOMAIN))->count();
        $this->record('Demo / default accounts (*'.DemoData::EMAIL_DOMAIN.')', $demoUsers === 0 ? 'PASS' : 'FAIL', (string) $demoUsers);

        $demoLeads = 0;
        foreach (DemoData::LEAD_NAMES as $name) {
            [$first, $last] = explode(' ', $name, 2);
            $demoLeads += $this->count('leads', fn ($q) => $q->where('first_name', $first)->where('last_name', $last));
        }
        $this->record('Demo leads', $demoLeads === 0 ? 'PASS' : 'FAIL', (string) $demoLeads);

        $demoTeams = $this->count('teams', fn ($q) => $q->whereIn('name', DemoData::TEAM_NAMES));
        $demoCampaigns = $this->count('campaigns', fn ($q) => $q->whereIn('name', DemoData::CAMPAIGN_NAMES));
        $this->record('Demo teams / campaigns', $demoTeams + $demoCampaigns === 0 ? 'PASS' : 'FAIL', (string) ($demoTeams + $demoCampaigns));
    }

    private function operationalFiles(): int
    {
        $disk = Storage::disk('local');

        return collect(DemoDataCleaner::FILE_DIRECTORIES)
            ->filter(fn ($d) => $disk->directoryExists($d))
            ->sum(fn ($d) => count($disk->allFiles($d)));
    }

    private function count(string $table, ?callable $scope = null): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        return DB::table($table)->when($scope, $scope)->count();
    }

    private function record(string $check, string $status, string $count): void
    {
        $this->rows[] = [$check, $status, $count];
    }
}
