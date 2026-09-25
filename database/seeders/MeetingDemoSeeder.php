<?php

namespace Database\Seeders;

use App\Models\Lead;
use App\Models\Meeting;
use App\Models\MeetingType;
use App\Models\User;
use App\Services\Meetings\MeetingCompletionService;
use App\Services\Meetings\MeetingService;
use App\Support\CrmTime;
use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\LocalDemoOnly;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Local-only demo meetings created through MeetingService /
 * MeetingCompletionService, so numbers, participants, reminders, activities
 * and audit entries are genuine. Skips if any meeting exists.
 */
class MeetingDemoSeeder extends Seeder
{
    use LocalDemoOnly;

    public function run(MeetingService $meetings, MeetingCompletionService $completion): void
    {
        if (! $this->demoSeedingAllowed()) {
            return;
        }
        if (Meeting::withTrashed()->exists()) {
            $this->command?->warn('Meetings already exist; demo meetings skipped.');

            return;
        }

        $users = User::whereIn('email', ['admin@salescrm.local', 'manager@salescrm.local', 'rahul@salescrm.local', 'priya@salescrm.local'])->get()->keyBy('email');
        if ($users->count() < 4 || ! Lead::exists()) {
            $this->command?->warn('Demo users or leads missing; demo meetings skipped.');

            return;
        }

        $admin = $users['admin@salescrm.local'];
        $manager = $users['manager@salescrm.local'];
        $rahul = $users['rahul@salescrm.local'];
        $priya = $users['priya@salescrm.local'];
        $type = MeetingType::pluck('id', 'slug');
        $tz = CrmTime::tz();

        $lead = fn (string $first, string $last) => Lead::where('first_name', $first)->where('last_name', $last)->first();
        $inMinutes = function (int $m) use ($tz) {
            $at = CarbonImmutable::now($tz)->addMinutes($m)->startOfMinute();

            return $at->setMinute(intdiv($at->minute, 15) * 15);
        };
        $tomorrowAt = fn (int $h, int $m = 0) => CarbonImmutable::now($tz)->addDay()->setTime($h, $m);
        $dayAfterAt = fn (int $h, int $m = 0) => CarbonImmutable::now($tz)->addDays(2)->setTime($h, $m);

        $create = function (User $actor, ?Lead $lead, string $slug, CarbonImmutable $start, int $minutes, array $extra = []) use ($meetings, $type): ?Meeting {
            Auth::setUser($actor);
            try {
                $end = $start->addMinutes($minutes);

                return $meetings->create($lead, [
                    'meeting_type_id' => $type[$slug],
                    'scheduled_date' => $start->format('Y-m-d'),
                    'start_time' => $start->format('H:i'),
                    'end_date' => $end->format('Y-m-d'),
                    'end_time' => $end->format('H:i'),
                    ...$extra,
                ], $actor);
            } catch (ValidationException $e) {
                $this->command?->warn('Demo meeting skipped: '.collect($e->errors())->flatten()->first());

                return null;
            } finally {
                Auth::forgetUser();
            }
        };

        $as = function (User $user, callable $fn) {
            Auth::setUser($user);
            try {
                return $fn();
            } finally {
                Auth::forgetUser();
            }
        };

        // Rahul — today's product demo with his manager and the lead attending.
        $create($rahul, $lead('Amit', 'Desai'), 'product_demo', $inMinutes(120), 60, [
            'title' => 'Product demo – home loan plans',
            'location_type' => 'office',
            'location' => 'Head Office, Conference Room 2',
            'address' => 'CG Road, Ahmedabad',
            'agenda' => "1. Loan options\n2. EMI comparison\n3. Documents checklist",
            'priority' => 'high',
            'reminders' => [30, 15],
            'participant_user_ids' => [$manager->id],
            'include_lead' => true,
        ]);

        // Rahul — tomorrow online.
        $create($rahul, $lead('Suresh', 'Nair'), 'google_meet', $tomorrowAt(11), 30, [
            'title' => 'Eligibility discussion',
            'location_type' => 'online',
            'meeting_url' => 'https://meet.google.com/demo-crm-link',
            'reminders' => [1440, 15],
            'include_lead' => true,
        ]);

        // Rahul — site visit that gets rescheduled (history kept).
        $visit = $create($rahul, $lead('Kavita', 'Joshi'), 'site_visit', $tomorrowAt(15), 90, [
            'title' => 'Site visit – Prahlad Nagar property',
            'location_type' => 'site',
            'location' => 'Client Property',
            'address' => 'Prahlad Nagar, Ahmedabad',
            'reminders' => [60],
        ]);
        if ($visit) {
            $as($rahul, fn () => $meetings->reschedule($visit, [
                'scheduled_date' => $dayAfterAt(11)->format('Y-m-d'),
                'start_time' => '11:00',
                'end_time' => '12:30',
                'reason' => 'Client requested a morning slot.',
            ], $rahul));
        }

        // Rahul — completed today.
        $done = $create($rahul, $lead('Ritu', 'Agarwal'), 'office_meeting', $inMinutes(30), 30, [
            'title' => 'Proposal walkthrough',
            'location_type' => 'office',
            'location' => 'Head Office',
        ]);
        if ($done) {
            $as($rahul, fn () => $completion->complete($done, [
                'outcome' => 'proposal_required',
                'notes' => 'Customer wants a written proposal with 20-year tenure options.',
            ], $rahul));
        }

        // Rahul — cancelled.
        $call = $create($rahul, $lead('Farhan', 'Qureshi'), 'phone_call', $tomorrowAt(17), 30, ['location_type' => 'phone']);
        if ($call) {
            $as($rahul, fn () => $meetings->cancel($call, 'Customer travelling this week.', $rahul));
        }

        // Priya — today at the client's office, and tomorrow on Zoom.
        $create($priya, $lead('Sneha', 'Iyer'), 'client_office', $inMinutes(180), 60, [
            'title' => 'Documents collection',
            'location_type' => 'client_location',
            'location' => 'Iyer & Co. office',
            'address' => 'Satellite, Ahmedabad',
            'reminders' => [30],
            'include_lead' => true,
        ]);
        $create($priya, $lead('Anjali', 'Rao'), 'zoom', $tomorrowAt(16), 30, [
            'title' => 'Rate negotiation',
            'location_type' => 'online',
            'meeting_url' => 'https://zoom.us/j/0000000000',
            'priority' => 'urgent',
        ]);

        // Manager — team review (no lead) and a Teams call with an external advisor.
        $create($manager, null, 'office_meeting', $tomorrowAt(9, 30), 30, [
            'title' => 'Weekly pipeline review',
            'location_type' => 'office',
            'location' => 'Ahmedabad office',
            'participant_user_ids' => [$rahul->id, $priya->id],
            'reminders' => [15],
        ]);
        $create($manager, $lead('Harsh', 'Trivedi'), 'microsoft_teams', $tomorrowAt(12), 30, [
            'title' => 'Escalation call with customer CA',
            'location_type' => 'online',
            'meeting_url' => 'https://teams.microsoft.com/l/meetup-join/demo',
            'external_participants' => [['name' => 'CA Rakesh Mehta', 'email' => 'rakesh.ca@example.com', 'phone' => '+91 98250 00000']],
        ]);

        // Admin — company planning (no lead).
        $create($admin, null, 'office_meeting', $tomorrowAt(18), 60, [
            'title' => 'Quarterly sales planning',
            'location_type' => 'office',
            'location' => 'Board room',
            'participant_user_ids' => [$manager->id],
        ]);

        $this->command?->info('Demo meetings created: '.Meeting::count());
    }
}
