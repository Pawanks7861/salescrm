<?php

namespace Database\Seeders;

use App\Models\Followup;
use App\Models\FollowupType;
use App\Models\Lead;
use App\Models\User;
use App\Services\Followups\FollowupReminderService;
use App\Services\Followups\FollowupService;
use App\Services\Followups\LeadFollowupSyncService;
use App\Support\CrmTime;
use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\LocalDemoOnly;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Local-only demo follow-ups created through FollowupService (so activities,
 * audit, reminders and next_followup_at are genuine). A few are then moved
 * into the past to demonstrate overdue work. Skips if any follow-up exists.
 */
class FollowupDemoSeeder extends Seeder
{
    use LocalDemoOnly;

    public function run(FollowupService $followups, LeadFollowupSyncService $sync, FollowupReminderService $reminders): void
    {
        if (! $this->demoSeedingAllowed()) {
            return;
        }
        if (Followup::withTrashed()->exists()) {
            $this->command?->warn('Follow-ups already exist; demo follow-ups skipped.');

            return;
        }

        $users = User::whereIn('email', ['manager@salescrm.local', 'rahul@salescrm.local', 'priya@salescrm.local'])->get()->keyBy('email');
        if ($users->count() < 3 || ! Lead::exists()) {
            $this->command?->warn('Demo users or leads missing; demo follow-ups skipped.');

            return;
        }

        $manager = $users['manager@salescrm.local'];
        $rahul = $users['rahul@salescrm.local'];
        $priya = $users['priya@salescrm.local'];
        $type = FollowupType::pluck('id', 'slug');
        $tz = CrmTime::tz();

        $as = function (User $user, callable $fn) {
            Auth::setUser($user);
            try {
                return $fn();
            } finally {
                Auth::forgetUser();
            }
        };

        $lead = fn (string $first, string $last) => Lead::where('first_name', $first)->where('last_name', $last)->first();
        $at = fn (int $minutesFromNow) => CarbonImmutable::now($tz)->addMinutes($minutesFromNow)->startOfMinute();

        // [actor, lead, type, minutes from now (negative = overdue), priority, title, assign to]
        $rows = [
            [$rahul, ['Amit', 'Desai'], 'call', 90, 'high', 'Share quotation and confirm budget', null],
            [$rahul, ['Kavita', 'Joshi'], 'call', -180, 'medium', 'Callback after brochure', null],
            [$rahul, ['Suresh', 'Nair'], 'demo', 1500, 'urgent', 'Product demo at office', null],
            [$rahul, ['Farhan', 'Qureshi'], 'whatsapp', -1500, 'medium', 'Send revised offer on WhatsApp', null],
            [$rahul, ['Isha', 'Kapoor'], 'email', 4320, 'low', null, null],
            [$priya, ['Sneha', 'Iyer'], 'site_visit', 240, 'high', 'Site visit – Satellite branch', null],
            [$priya, ['Vikram', 'Singh'], 'call', -60, 'medium', 'Follow up on eligibility documents', null],
            [$priya, ['Anjali', 'Rao'], 'call', 2880, 'urgent', 'Negotiate final rate', null],
            [$manager, ['Harsh', 'Trivedi'], 'call', 1440, 'high', 'Manager escalation call', null],
            [$manager, ['Nikhil', 'Chauhan'], 'call', 120, 'high', 'Check loan sanction status', $priya],
        ];

        $overdue = [];
        foreach ($rows as [$actor, $name, $slug, $minutes, $priority, $title, $assignTo]) {
            $target = $lead(...$name);
            if (! $target) {
                continue;
            }

            $scheduled = $at(max($minutes, 5));
            $followup = $as($actor, fn () => $followups->create($target, [
                'followup_type_id' => $type[$slug],
                'scheduled_date' => $scheduled->format('Y-m-d'),
                'scheduled_time' => $scheduled->format('H:i'),
                'priority' => $priority,
                'title' => $title,
                'assigned_to' => $assignTo?->id,
            ], $actor, true));

            if ($minutes < 0) {
                $overdue[$followup->id] = $at($minutes)->utc();
            }
        }

        // Move a few into the past (demo only) to show overdue handling.
        foreach ($overdue as $id => $past) {
            DB::table('followups')->where('id', $id)->update(['scheduled_at' => $past]);
            $f = Followup::find($id);
            $reminders->schedule($f);
            $sync->sync($f->lead_id);
        }

        // One completed and one completed-with-next example on Rahul's leads.
        $done = $lead('Ritu', 'Agarwal');
        if ($done) {
            $first = $as($rahul, fn () => $followups->create($done, [
                'followup_type_id' => $type['call'],
                'scheduled_date' => $at(10)->format('Y-m-d'),
                'scheduled_time' => $at(10)->format('H:i'),
                'priority' => 'medium',
                'title' => 'Intro call',
            ], $rahul, true));

            $as($rahul, fn () => $followups->complete($first, [
                'outcome' => 'interested',
                'notes' => 'Customer interested in 20-year tenure. Wants EMI chart.',
                'next_action' => 'Email EMI chart',
                'schedule_next' => true,
                'next' => [
                    'followup_type_id' => $type['email'],
                    'scheduled_date' => $at(1440 + 60)->format('Y-m-d'),
                    'scheduled_time' => $at(1440 + 60)->format('H:i'),
                    'priority' => 'medium',
                    'title' => 'Send EMI chart',
                ],
            ], $rahul));
        }

        $this->command?->info('Demo follow-ups created: '.Followup::count());
    }
}
