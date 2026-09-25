<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 — reporting support. Additive only.
 *
 *  - lead_status_changes: queryable status-transition history (funnel, stage
 *    duration, period wins/losses). Backfilled from the existing activity
 *    timeline, which has recorded every LeadService::changeStatus() since
 *    Phase 2 (properties.from_status_id / to_status_id).
 *  - report_exports: private, expiring export files.
 *  - lead_assignments.created_at index for assignment analytics by period.
 *  - Sales Executives receive report.view (own data only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->restrictOnDelete();
            $table->foreignId('from_status_id')->nullable()->constrained('lead_statuses')->restrictOnDelete();
            $table->foreignId('to_status_id')->constrained('lead_statuses')->restrictOnDelete();
            $table->timestamp('changed_at');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            // Owner / team at the time of the change (lead metrics use the current owner by default).
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->boolean('is_backfilled')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->index(['lead_id', 'changed_at']);
            $table->index(['to_status_id', 'changed_at']);
            $table->index('changed_at');
        });

        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('report', 40);
            $table->string('section', 40);
            $table->string('format', 10)->default('csv');
            $table->json('filters_json')->nullable();
            $table->string('status', 20)->default('queued')->index();
            $table->unsignedInteger('row_count')->nullable();
            $table->string('disk', 50)->nullable();
            $table->string('path', 500)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::table('lead_assignments', function (Blueprint $table) {
            $table->index('created_at');
        });

        $this->backfillStatusHistory();
        $this->grantExecutiveReportView();
    }

    public function down(): void
    {
        Schema::table('lead_assignments', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
        Schema::dropIfExists('report_exports');
        Schema::dropIfExists('lead_status_changes');
    }

    private function backfillStatusHistory(): void
    {
        $types = ['status_changed', 'lead_won', 'lead_lost', 'lead_reopened'];
        $statusIds = DB::table('lead_statuses')->pluck('id')->flip();
        $now = now();

        DB::table('leads')->orderBy('id')->select(['id', 'status_id', 'assigned_to', 'team_id', 'created_by', 'created_at'])
            ->chunkById(500, function ($leads) use ($types, $statusIds, $now) {
                $transitions = DB::table('activities')
                    ->where('subject_type', 'App\\Models\\Lead')
                    ->whereIn('subject_id', $leads->pluck('id'))
                    ->whereIn('type', $types)
                    ->orderBy('created_at')->orderBy('id')
                    ->get(['subject_id', 'user_id', 'properties', 'created_at'])
                    ->groupBy('subject_id');

                $rows = [];
                foreach ($leads as $lead) {
                    $history = collect($transitions->get($lead->id, []))
                        ->map(function ($a) {
                            $p = json_decode((string) $a->properties, true) ?: [];

                            return isset($p['to_status_id']) ? ['from' => $p['from_status_id'] ?? null, 'to' => (int) $p['to_status_id'], 'by' => $a->user_id, 'at' => $a->created_at] : null;
                        })
                        ->filter(fn ($t) => $t && $statusIds->has($t['to']))
                        ->values();

                    $initial = $history->first()['from'] ?? $lead->status_id;
                    if (! $statusIds->has($initial)) {
                        $initial = $lead->status_id;
                    }

                    $rows[] = ['lead_id' => $lead->id, 'from_status_id' => null, 'to_status_id' => $initial, 'changed_at' => $lead->created_at, 'changed_by' => $lead->created_by,
                        'assigned_to' => null, 'team_id' => null, 'is_backfilled' => true, 'created_at' => $now];

                    foreach ($history as $t) {
                        $rows[] = ['lead_id' => $lead->id, 'from_status_id' => $t['from'] && $statusIds->has($t['from']) ? $t['from'] : null, 'to_status_id' => $t['to'], 'changed_at' => $t['at'],
                            'changed_by' => $t['by'], 'assigned_to' => null, 'team_id' => null, 'is_backfilled' => true, 'created_at' => $now];
                    }
                }

                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('lead_status_changes')->insert($chunk);
                }
            });
    }

    private function grantExecutiveReportView(): void
    {
        $role = DB::table('roles')->where('slug', 'sales_executive')->value('id');
        $permission = DB::table('permissions')->where('name', 'report.view')->value('id');
        if (! $role || ! $permission) {
            return;
        }

        DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
    }
};
