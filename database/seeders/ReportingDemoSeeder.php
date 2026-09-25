<?php

namespace Database\Seeders;

use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Meeting;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Services\Leads\LeadService;
use App\Services\Meetings\MeetingService;
use App\Support\CrmTime;
use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\LocalDemoOnly;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Local-only data that makes the reports meaningful: spreads the demo status
 * history over each lead's life, adds a second team with its own executive
 * and leads, historical calls with every outcome, a repeat Meta enquiry and
 * a no-show meeting. Refuses to run outside the local environment and skips
 * itself once demo calls exist.
 */
class ReportingDemoSeeder extends Seeder
{
    use LocalDemoOnly;

    public function run(LeadService $leads, MeetingService $meetings): void
    {
        if (! $this->demoSeedingAllowed()) {
            return;
        }
        if (DB::table('calls')->where('provider_call_id', 'like', 'DEMO-%')->exists()) {
            $this->command?->warn('Reporting demo data already present; skipped.');

            return;
        }

        $users = User::whereIn('email', ['admin@salescrm.local', 'manager@salescrm.local', 'rahul@salescrm.local', 'priya@salescrm.local'])->get()->keyBy('email');
        if ($users->count() < 4 || ! Lead::exists()) {
            $this->command?->warn('Run DemoSeeder first; reporting demo data skipped.');

            return;
        }
        $admin = $users['admin@salescrm.local'];

        $this->mumbaiTeam($leads, $admin);
        $this->spreadStatusHistory();
        $this->calls();
        $this->repeatMetaEnquiry();
        $this->noShowMeeting($meetings, $users['rahul@salescrm.local']);

        $this->command?->info('Reporting demo data created (calls: '.DB::table('calls')->where('provider_call_id', 'like', 'DEMO-%')->count().').');
    }

    /** Second team so manager isolation is visible in reports. */
    private function mumbaiTeam(LeadService $leads, User $admin): void
    {
        $team = Team::firstOrCreate(['name' => 'Mumbai Team'], ['is_active' => true]);
        $arjun = User::firstOrNew(['email' => 'arjun@salescrm.local']);
        if (! $arjun->exists) {
            $arjun->fill(['name' => 'Arjun Mehta', 'password' => 'Password@123', 'designation' => 'Sales Executive']);
            $arjun->role_id = Role::where('slug', 'sales_executive')->value('id');
            $arjun->team_id = $team->id;
            $arjun->is_active = true;
            $arjun->email_verified_at = now();
            $arjun->save();
        }
        $team->members()->syncWithoutDetaching([$arjun->id]);

        $source = LeadSource::pluck('id', 'slug');
        $status = LeadStatus::pluck('id', 'slug');
        $rows = [
            ['Rohan', 'Kulkarni', '9930011111', 'referral', 'won', 20, 900000],
            ['Swati', 'Deshmukh', '9930022222', 'google_ads', 'negotiation', 9, 650000],
            ['Aditya', 'Joshi', '9930033333', 'website', 'contacted', 4, 150000],
            ['Pallavi', 'Sawant', '9930044444', 'walk_in', 'lost', 15, 300000],
            ['Kunal', 'Shetty', '9930055555', 'phone_call', 'new', 0, null],
        ];

        Auth::setUser($admin);
        try {
            foreach ($rows as [$first, $last, $phone, $src, $slug, $age, $value]) {
                $lead = $leads->create([
                    'first_name' => $first, 'last_name' => $last, 'phone' => $phone, 'city' => 'Mumbai', 'state' => 'Maharashtra',
                    'country' => 'India', 'source_id' => $source[$src], 'priority' => 'medium', 'estimated_value' => $value,
                    'assigned_to' => $arjun->id,
                ], $admin, true);
                if ($slug !== 'new') {
                    $leads->changeStatus($lead, $status[$slug], $arjun, $slug === 'lost' ? (int) DB::table('lost_reasons')->value('id') : null);
                }
                if ($age > 0) {
                    $at = now()->subDays($age);
                    DB::table('leads')->where('id', $lead->id)->update(['created_at' => $at]);
                    DB::table('activities')->where('subject_type', $lead->getMorphClass())->where('subject_id', $lead->id)
                        ->where('type', 'lead_created')->update(['created_at' => $at]);
                }
            }
        } finally {
            Auth::forgetUser();
        }
    }

    /**
     * The demo leads were backdated after their status changes were recorded,
     * so every change carries the seeding time. Spread them over each lead's
     * life (initial status at creation) so stage timing and period outcomes
     * look like real usage.
     */
    private function spreadStatusHistory(): void
    {
        $now = CarbonImmutable::now();

        Lead::withTrashed()->select('id', 'created_at')->chunkById(200, function ($chunk) use ($now) {
            foreach ($chunk as $lead) {
                $rows = DB::table('lead_status_changes')->where('lead_id', $lead->id)->orderBy('id')->pluck('id');
                $created = CarbonImmutable::parse($lead->created_at);
                $span = max(60, $created->diffInSeconds($now));

                foreach ($rows as $i => $id) {
                    $at = $i === 0 ? $created : $created->addSeconds((int) ($span * (0.3 + 0.6 * $i / max(1, $rows->count() - 1))));
                    DB::table('lead_status_changes')->where('id', $id)->update(['changed_at' => $at->min($now)]);
                }
            }
        });
    }

    /** Historical calls (connected, no answer, busy, missed) attributed to each lead's owner. */
    private function calls(): void
    {
        $integration = DB::table('telephony_integrations')->value('id');
        $outcomes = [['completed', 420], ['no_answer', null], ['completed', 185], ['busy', null], ['completed', 610], ['failed', null]];
        $n = 0;

        Lead::query()->whereNotNull('assigned_to')->with('assignee:id,team_id')->orderBy('id')->get()->each(function (Lead $lead) use ($integration, $outcomes, &$n) {
            $created = CarbonImmutable::parse($lead->created_at);
            $count = 1 + ($lead->id % 3);

            for ($i = 0; $i < $count; $i++) {
                [$status, $talk] = $outcomes[($lead->id + $i) % count($outcomes)];
                $started = $created->addMinutes(4 + $i * 90 + ($lead->id % 7) * 3)->min(now()->subMinutes(10));
                $answered = $talk ? $started->addSeconds(12) : null;
                $n++;

                DB::table('calls')->insert([
                    'call_number' => sprintf('DEMO-CALL-%05d', $n),
                    'client_reference' => (string) Str::uuid(),
                    'lead_id' => $lead->id,
                    'agent_user_id' => $lead->assigned_to,
                    'team_id' => $lead->assignee?->team_id,
                    'integration_id' => $integration,
                    'provider' => 'fake',
                    'provider_call_id' => 'DEMO-'.$n,
                    'direction' => 'outbound',
                    'channel' => 'pstn',
                    'contact_field' => 'phone',
                    'customer_number_normalized' => $lead->normalized_phone,
                    'status' => $status,
                    'started_at' => $started,
                    'answered_at' => $answered,
                    'ended_at' => $answered ? $answered->addSeconds($talk) : $started->addSeconds(30),
                    'ring_duration_seconds' => $talk ? 12 : 30,
                    'talk_duration_seconds' => $talk,
                    'total_duration_seconds' => $talk ? $talk + 12 : 30,
                    'created_at' => $started,
                    'updated_at' => $started,
                ]);
            }
        });

        // One missed inbound call.
        $lead = Lead::query()->whereNotNull('assigned_to')->orderByDesc('id')->first();
        if ($lead) {
            DB::table('calls')->insert([
                'call_number' => sprintf('DEMO-CALL-%05d', ++$n), 'client_reference' => (string) Str::uuid(), 'lead_id' => $lead->id,
                'agent_user_id' => $lead->assigned_to, 'team_id' => $lead->team_id, 'integration_id' => $integration, 'provider' => 'fake',
                'provider_call_id' => 'DEMO-'.$n, 'direction' => 'inbound', 'channel' => 'pstn', 'customer_number_normalized' => $lead->normalized_phone,
                'status' => 'missed', 'started_at' => now()->subHours(3), 'ended_at' => now()->subHours(3)->addSeconds(25),
                'ring_duration_seconds' => 25, 'created_at' => now()->subHours(3), 'updated_at' => now()->subHours(3),
            ]);
        }
    }

    /** The same person submitting a second Meta form: one lead, two enquiries. */
    private function repeatMetaEnquiry(): void
    {
        $lead = Lead::whereNotNull('facebook_lead_id')->orderBy('id')->first();
        if (! $lead) {
            return;
        }

        DB::table('lead_enquiries')->insert([
            'lead_id' => $lead->id,
            'source_id' => $lead->source_id,
            'campaign_id' => $lead->campaign_id,
            'channel' => 'facebook',
            'external_id' => $lead->facebook_lead_id.'-2',
            'enquiry_data_json' => json_encode(['full_name' => $lead->full_name, 'loan_amount' => '50-75 lakh']),
            'is_duplicate' => true,
            'received_at' => now()->subHours(5),
            'created_at' => now()->subHours(5),
            'updated_at' => now()->subHours(5),
        ]);
    }

    private function noShowMeeting(MeetingService $meetings, User $rahul): void
    {
        $lead = Lead::where('assigned_to', $rahul->id)->whereNull('deleted_at')->orderBy('id')->first();
        if (! $lead) {
            return;
        }

        Auth::setUser($rahul);
        try {
            $tomorrow = CarbonImmutable::now(CrmTime::tz())->addDay();
            $meeting = $meetings->create($lead, [
                'meeting_type_id' => DB::table('meeting_types')->value('id'), 'title' => 'Office visit',
                'scheduled_date' => $tomorrow->format('Y-m-d'), 'start_time' => '16:00', 'end_time' => '16:30',
                'location_type' => 'office', 'priority' => 'medium', 'reminders' => [],
            ], $rahul, true);

            $start = now()->subDays(2)->setTime(10, 0);
            Meeting::whereKey($meeting->id)->update(['start_at' => $start, 'end_at' => $start->copy()->addMinutes(30)]);
            $meetings->markNoShow($meeting->fresh(), ['notes' => 'Customer did not arrive.'], $rahul);
        } finally {
            Auth::forgetUser();
        }
    }
}
