<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Team-based visibility was removed. The legacy permission rows (and any
 * role/user grants of them) are kept for rollback safety but relabelled; the
 * application strips them from every resolved permission set
 * (App\Support\Permissions::DEPRECATED). Teams tables and team_id columns are
 * untouched.
 */
return new class extends Migration
{
    private const LABELS = [
        'lead.view_team' => ['Leads', 'View team leads'],
        'followup.view_team' => ['Follow-ups', 'View team follow-ups'],
        'meeting.view_team' => ['Meetings', 'View team meetings'],
        'call.view_team' => ['Calls', 'View team calls'],
        'report.view_team' => ['Reports', 'View team reports'],
        'team.view' => ['Teams', 'View teams'],
        'team.manage' => ['Teams', 'Manage teams'],
    ];

    public function up(): void
    {
        foreach (self::LABELS as $name => [, $label]) {
            DB::table('permissions')->where('name', $name)->update([
                'module' => 'Deprecated',
                'label' => $label.' (deprecated, no effect)',
            ]);
        }
    }

    public function down(): void
    {
        foreach (self::LABELS as $name => [$module, $label]) {
            DB::table('permissions')->where('name', $name)->update(['module' => $module, 'label' => $label]);
        }
    }
};
