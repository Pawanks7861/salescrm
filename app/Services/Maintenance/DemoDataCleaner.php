<?php

namespace App\Services\Maintenance;

use App\Models\User;
use App\Services\SettingService;
use App\Support\DemoData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Removes operational (business) records and demo accounts from a
 * pre-production database while keeping system configuration: roles,
 * permissions, lookup values, custom field definitions, settings and
 * branding. Deletes run in foreign-key order inside one transaction with
 * foreign-key checks left on, so a missed dependency rolls everything back.
 */
class DemoDataCleaner
{
    /**
     * Children before parents (see the foreign keys in database/migrations).
     * Self-references are cleared first in clearSelfReferences().
     */
    public const OPERATIONAL_TABLES = [
        'facebook_webhook_events', 'facebook_field_mappings', 'facebook_forms', 'facebook_pages', 'facebook_integrations',
        'meeting_reminders', 'meeting_participants', 'meetings',
        'followup_reminders', 'followups',
        'lead_note_histories', 'lead_notes', 'lead_custom_field_values', 'lead_status_changes', 'lead_assignments',
        'lead_enquiries', 'attachments', 'activities', 'batch_trainers', 'batch_leads', 'batches', 'leads',
        'lead_assignment_rules', 'campaigns',
        'notifications', 'push_subscriptions', 'report_exports',
        'login_histories', 'sessions', 'password_reset_tokens', 'audit_logs',
        'jobs', 'job_batches', 'failed_jobs', 'number_sequences',
        'team_users', 'teams',
    ];

    /** Private-disk directories that only hold operational files. */
    public const FILE_DIRECTORIES = ['leads', 'report-exports', 'exports', 'imports', 'tmp'];

    public function __construct(private readonly SettingService $settings) {}

    /** @return array<string, int> row counts of every table the cleaner empties */
    public function counts(): array
    {
        $counts = [];
        foreach (self::OPERATIONAL_TABLES as $table) {
            if (Schema::hasTable($table)) {
                $counts[$table] = DB::table($table)->count();
            }
        }
        $counts['users (to remove)'] = $this->usersToRemove(false)->count();

        return $counts;
    }

    /** @return Builder<User> */
    public function usersToRemove(bool $allUsers)
    {
        $superAdmin = fn ($q) => $q->where('slug', User::SUPER_ADMIN_ROLE);

        return User::withTrashed()
            ->where(fn ($q) => $q->whereDoesntHave('role', $superAdmin)->orWhereNull('role_id'))
            ->when(! $allUsers, fn ($q) => $q->where(fn ($q) => $q
                ->whereIn('email', DemoData::USER_EMAILS)
                ->orWhere('email', 'like', '%'.DemoData::EMAIL_DOMAIN)));
    }

    /**
     * @return array{rows: array<string, int>, users: int, files: int, kept_users: int}
     */
    public function clear(bool $allUsers = false): array
    {
        $files = $this->operationalFiles();
        $rows = [];
        $removedUsers = 0;

        DB::transaction(function () use ($allUsers, &$rows, &$removedUsers) {
            $this->clearSelfReferences();

            foreach (self::OPERATIONAL_TABLES as $table) {
                if (Schema::hasTable($table)) {
                    $rows[$table] = DB::table($table)->delete();
                }
            }

            $ids = $this->usersToRemove($allUsers)->pluck('id');
            DB::table('user_permissions')->whereIn('user_id', $ids)->delete();
            $removedUsers = DB::table('users')->whereIn('id', $ids)->delete();

            DB::table('users')->update(['remember_token' => null]);
        });

        $deletedFiles = $this->deleteFiles($files);
        $this->settings->flush();
        Cache::flush();

        return [
            'rows' => $rows,
            'users' => $removedUsers,
            'files' => $deletedFiles,
            'kept_users' => User::withTrashed()->count(),
        ];
    }

    private function clearSelfReferences(): void
    {
        foreach (['meetings' => 'rescheduled_from_id', 'followups' => 'rescheduled_from_id', 'leads' => 'duplicate_of_id'] as $table => $column) {
            if (Schema::hasColumn($table, $column)) {
                DB::table($table)->whereNotNull($column)->update([$column => null]);
            }
        }
        // Legacy team columns and manager links stay structurally, empty.
        DB::table('users')->update(['manager_id' => null, 'team_id' => null]);
    }

    /** @return list<array{0: string, 1: string}> [disk, path] of files owned by operational rows */
    private function operationalFiles(): array
    {
        $files = [];
        foreach (['attachments', 'report_exports'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (DB::table($table)->whereNotNull('path')->whereNotNull('disk')->get(['disk', 'path']) as $row) {
                $files[] = [(string) $row->disk, (string) $row->path];
            }
        }

        return $files;
    }

    /** @param  list<array{0: string, 1: string}>  $files */
    private function deleteFiles(array $files): int
    {
        $deleted = 0;
        foreach ($files as [$disk, $path]) {
            try {
                if ($disk !== 'branding' && Storage::disk($disk)->exists($path) && Storage::disk($disk)->delete($path)) {
                    $deleted++;
                }
            } catch (Throwable) {
                // Unknown or unreachable disk: reported by the file count only.
            }
        }

        $local = Storage::disk('local');
        foreach (self::FILE_DIRECTORIES as $directory) {
            if ($local->directoryExists($directory)) {
                $deleted += count($local->allFiles($directory));
                $local->deleteDirectory($directory);
            }
        }

        return $deleted;
    }
}
