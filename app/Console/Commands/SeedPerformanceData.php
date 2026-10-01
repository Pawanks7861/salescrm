<?php

namespace App\Console\Commands;

use App\Models\FollowupType;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\MeetingType;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Local/staging-copy performance dataset (Phase 8 §66). Bulk-inserts leads,
 * follow-ups and meetings spread over the active sales users so list
 * pages, dashboards, calendars and reports can be timed at realistic volume.
 * Every row is tagged with the PERF- prefix; --purge removes only those rows.
 * Refuses to run outside local/testing.
 */
class SeedPerformanceData extends Command
{
    private const PREFIX = 'PERF-';

    private const CHUNK = 1000;

    protected $signature = 'crm:seed-performance
        {--leads=5000} {--followups=10000} {--meetings=5000}
        {--purge : Delete previously generated PERF- rows instead of creating}';

    protected $description = 'Generate (or purge) a tagged performance-test dataset. Local/testing only.';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('crm:seed-performance only runs in the local/testing environment.');

            return self::FAILURE;
        }

        if ($this->option('purge')) {
            $this->purge();

            return self::SUCCESS;
        }

        $users = User::query()->where('is_active', true)->get(['id'])->all();
        $sourceIds = LeadSource::query()->pluck('id')->all();
        $statusIds = LeadStatus::query()->pluck('id')->all();
        $followupTypeIds = FollowupType::query()->pluck('id')->all();
        $meetingTypeIds = MeetingType::query()->pluck('id')->all();

        if ($users === [] || $sourceIds === [] || $statusIds === [] || $followupTypeIds === [] || $meetingTypeIds === []) {
            $this->error('Needs active users and seeded reference data (php artisan db:seed).');

            return self::FAILURE;
        }

        $started = microtime(true);
        $runId = Str::upper(Str::random(4));
        $now = now();

        $leadIds = array_values(array_filter(
            $this->leads((int) $this->option('leads'), $runId, $users, $sourceIds, $statusIds, $now),
            fn (array $lead) => $lead['user'] !== null,
        ));
        $this->followups((int) $this->option('followups'), $leadIds, $followupTypeIds, $now);
        $this->meetings((int) $this->option('meetings'), $runId, $leadIds, $meetingTypeIds, $now);

        $this->info(sprintf('Performance dataset created in %.1fs (run %s).', microtime(true) - $started, $runId));

        return self::SUCCESS;
    }

    /** @return array<int, array{id: int, user: ?int}> */
    private function leads(int $count, string $runId, array $users, array $sourceIds, array $statusIds, Carbon $now): array
    {
        $priorities = ['low', 'medium', 'medium', 'high', 'urgent'];
        $bar = $this->output->createProgressBar($count);
        $bar->setFormat(' leads     %current%/%max% [%bar%]');

        for ($i = 0; $i < $count; $i += self::CHUNK) {
            $rows = [];
            foreach (range($i, min($count, $i + self::CHUNK) - 1) as $n) {
                $user = $users[$n % count($users)];
                $phone = '7'.str_pad((string) (crc32($runId) % 1000), 3, '0', STR_PAD_LEFT).str_pad((string) $n, 6, '0', STR_PAD_LEFT);
                $created = $now->copy()->subMinutes(random_int(0, 60 * 24 * 365));
                $rows[] = [
                    'lead_number' => self::PREFIX.$runId.'-'.str_pad((string) $n, 6, '0', STR_PAD_LEFT),
                    'first_name' => 'Perf',
                    'last_name' => "Lead {$n}",
                    'full_name' => "Perf Lead {$n}",
                    'email' => strtolower("perf.{$runId}.{$n}@example.test"),
                    'phone' => $phone,
                    'normalized_phone' => '91'.$phone,
                    'source_id' => $sourceIds[$n % count($sourceIds)],
                    'status_id' => $statusIds[$n % count($statusIds)],
                    'priority' => $priorities[$n % count($priorities)],
                    'assigned_to' => $n % 20 === 0 ? null : $user->id,
                    'city' => 'Ahmedabad',
                    'country' => 'India',
                    'created_at' => $created,
                    'updated_at' => $created,
                ];
            }
            DB::table('leads')->insert($rows);
            $bar->advance(count($rows));
        }
        $bar->finish();
        $this->newLine();

        return DB::table('leads')->where('lead_number', 'like', self::PREFIX.$runId.'-%')
            ->get(['id', 'assigned_to'])
            ->map(fn ($l) => ['id' => $l->id, 'user' => $l->assigned_to])
            ->all();
    }

    private function followups(int $count, array $leads, array $typeIds, Carbon $now): void
    {
        $this->bulk('followups', $count, function (int $n) use ($leads, $typeIds, $now) {
            $lead = $leads[$n % count($leads)];
            $at = $now->copy()->addMinutes(random_int(-60 * 24 * 60, 60 * 24 * 30))->startOfMinute();
            $done = $at->isPast() && $n % 3 !== 0;

            return [
                'lead_id' => $lead['id'],
                'assigned_to' => $lead['user'],
                'followup_type_id' => $typeIds[$n % count($typeIds)],
                'title' => self::PREFIX.'follow-up '.$n,
                'scheduled_at' => $at,
                'timezone' => 'Asia/Kolkata',
                'status' => $done ? 'completed' : 'pending',
                'priority' => 'medium',
                'completed_at' => $done ? $at->copy()->addMinutes(20) : null,
                'completed_by' => $done ? $lead['user'] : null,
                'reminder_minutes_before' => 15,
                'created_at' => $at->copy()->subDay(),
                'updated_at' => $at->copy()->subDay(),
            ];
        });
    }

    private function meetings(int $count, string $runId, array $leads, array $typeIds, Carbon $now): void
    {
        $statuses = ['scheduled', 'confirmed', 'completed', 'completed', 'cancelled', 'no_show'];

        $this->bulk('meetings', $count, function (int $n) use ($leads, $typeIds, $now, $runId, $statuses) {
            $lead = $leads[$n % count($leads)];
            $start = $now->copy()->addHours(random_int(-24 * 90, 24 * 30))->startOfHour();
            $status = $start->isFuture() ? $statuses[$n % 2] : $statuses[2 + $n % 4];

            return [
                'meeting_number' => self::PREFIX.$runId.'-M'.str_pad((string) $n, 6, '0', STR_PAD_LEFT),
                'lead_id' => $lead['id'],
                'title' => self::PREFIX.'meeting '.$n,
                'meeting_type_id' => $typeIds[$n % count($typeIds)],
                'host_user_id' => $lead['user'],
                'start_at' => $start,
                'end_at' => $start->copy()->addHour(),
                'timezone' => 'Asia/Kolkata',
                'location_type' => 'office',
                'status' => $status,
                'priority' => 'medium',
                'reminder_offsets' => '[]',
                'completed_at' => $status === 'completed' ? $start->copy()->addHour() : null,
                'created_at' => $start->copy()->subDays(2),
                'updated_at' => $start->copy()->subDays(2),
            ];
        });

        DB::statement(
            "insert into meeting_participants (meeting_id, participant_type, user_id, name, attendance_status, created_at, updated_at)
             select m.id, 'user', m.host_user_id, u.name, 'pending', m.created_at, m.created_at
             from meetings m join users u on u.id = m.host_user_id
             where m.meeting_number like ?",
            [self::PREFIX.$runId.'-M%']
        );
    }

    private function bulk(string $table, int $count, callable $row): void
    {
        $bar = $this->output->createProgressBar($count);
        $bar->setFormat(' '.str_pad($table, 10).'%current%/%max% [%bar%]');

        for ($i = 0; $i < $count; $i += self::CHUNK) {
            $rows = array_map($row, range($i, min($count, $i + self::CHUNK) - 1));
            DB::table($table)->insert($rows);
            $bar->advance(count($rows));
        }
        $bar->finish();
        $this->newLine();
    }

    private function purge(): void
    {
        $like = self::PREFIX.'%';
        $leadIds = DB::table('leads')->where('lead_number', 'like', $like)->pluck('id');

        $deleted = DB::transaction(function () use ($like, $leadIds) {
            $meetingIds = DB::table('meetings')->where('meeting_number', 'like', $like)->pluck('id');

            return [
                'meeting_participants' => DB::table('meeting_participants')->whereIn('meeting_id', $meetingIds)->delete(),
                'meetings' => DB::table('meetings')->whereIn('id', $meetingIds)->delete(),
                'followups' => DB::table('followups')->where('title', 'like', $like)->whereIn('lead_id', $leadIds)->delete(),
                'leads' => DB::table('leads')->whereIn('id', $leadIds)->delete(),
            ];
        });

        foreach ($deleted as $table => $rows) {
            $this->line(sprintf('  %-22s %d', $table, $rows));
        }
        $this->info('PERF- rows removed.');
    }
}
