<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The telephony / calling module (provider click-to-call, browser calling,
 * call history, recordings, dispositions) was removed from the CRM.
 *
 * No other table has a foreign key into the telephony tables, so they are
 * dropped children-first. Lead phone numbers are untouched. Historical
 * audit_logs rows (CALL_* actions) and lead activities that mention calls are
 * kept as plain history; activities point at the lead, not at a call.
 *
 * Recording files that were archived to private storage are NOT deleted here:
 * remove storage/app/private/call-recordings/ manually after taking a backup.
 *
 * down() recreates the empty table structures only. Call data, call.*
 * permissions and telephony.* settings are not restored.
 */
return new class extends Migration
{
    private const TABLES = [
        'call_recordings',
        'call_events',
        'calls',
        'call_dispositions',
        'telephony_users',
        'telephony_numbers',
        'telephony_integrations',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }

        $permissionIds = DB::table('permissions')->where('name', 'like', 'call.%')->pluck('id');
        if ($permissionIds->isNotEmpty()) {
            DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('user_permissions')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }

        DB::table('settings')->where('key', 'like', 'telephony.%')->delete();
        DB::table('number_sequences')->where('prefix', 'like', 'C:%')->delete();
        DB::table('notifications')->where('type', 'App\\Notifications\\Calls\\MissedCallNotification')->delete();

        Cache::forever('permissions.version', (int) Cache::get('permissions.version', 1) + 1);
        Cache::forget('settings.all');
    }

    public function down(): void
    {
        if (Schema::hasTable('calls')) {
            return;
        }

        (require database_path('migrations/2026_09_24_500001_create_telephony_tables.php'))->up();
    }
};
