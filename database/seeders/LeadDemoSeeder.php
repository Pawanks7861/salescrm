<?php

namespace Database\Seeders;

use App\Enums\AssignmentType;
use App\Enums\NoteVisibility;
use App\Enums\RuleAssignment;
use App\Enums\RuleCondition;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\LeadAssignmentRule;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\LostReason;
use App\Models\Team;
use App\Models\User;
use App\Services\Leads\LeadNoteService;
use App\Services\Leads\LeadService;
use Database\Seeders\Concerns\LocalDemoOnly;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Local-only demo leads created through the real services so that numbering,
 * assignment history, activities and audit entries are all genuine.
 * Skips entirely if any lead already exists.
 */
class LeadDemoSeeder extends Seeder
{
    use LocalDemoOnly;

    public function run(LeadService $leads, LeadNoteService $notes): void
    {
        if (! $this->demoSeedingAllowed()) {
            return;
        }
        if (Lead::withTrashed()->exists()) {
            $this->command?->warn('Leads already exist; demo leads skipped.');

            return;
        }

        $users = User::whereIn('email', ['admin@salescrm.local', 'manager@salescrm.local', 'rahul@salescrm.local', 'priya@salescrm.local'])->get()->keyBy('email');
        if ($users->count() < 4) {
            $this->command?->warn('Demo users missing; run DemoSeeder first.');

            return;
        }

        $admin = $users['admin@salescrm.local'];
        $manager = $users['manager@salescrm.local'];
        $rahul = $users['rahul@salescrm.local'];
        $priya = $users['priya@salescrm.local'];

        $source = LeadSource::pluck('id', 'slug');
        $status = LeadStatus::pluck('id', 'slug');
        $reasons = LostReason::pluck('id', 'name');
        $team = Team::where('name', 'Ahmedabad Team')->first();

        $diwali = Campaign::firstOrCreate(['platform' => 'facebook', 'external_id' => '120210000000001'], [
            'name' => 'Diwali Home Loan Offer 2026', 'source_id' => $source['facebook'], 'is_active' => true,
            'starts_at' => now()->subDays(40)->toDateString(), 'ends_at' => now()->addDays(20)->toDateString(),
        ]);
        $website = Campaign::firstOrCreate(['platform' => 'manual', 'name' => 'Website Enquiry Form'], [
            'source_id' => $source['website'], 'is_active' => true,
        ]);

        $as = function (User $user, callable $fn) {
            Auth::setUser($user);
            try {
                return $fn();
            } finally {
                Auth::forgetUser();
            }
        };

        // [creator, assignee, first, last, phone, email, company, city, sourceSlug, campaign, priority, statusSlug, ageDays, value]
        $rows = [
            [$rahul, null, 'Amit', 'Desai', '9824011111', 'amit.desai@example.com', 'Desai Traders', 'Ahmedabad', 'walk_in', null, 'high', 'interested', 1, 250000],
            [$rahul, null, 'Kavita', 'Joshi', '9824022222', 'kavita.j@example.com', null, 'Ahmedabad', 'referral', null, 'medium', 'contacted', 3, 120000],
            [$rahul, null, 'Suresh', 'Nair', '9824033333', null, 'Nair Logistics', 'Gandhinagar', 'phone_call', null, 'urgent', 'negotiation', 6, 780000],
            [$rahul, null, 'Pooja', 'Mehta', '9824044444', 'pooja.mehta@example.com', null, 'Ahmedabad', 'website', $website, 'low', 'new', 0, null],
            [$rahul, null, 'Rakesh', 'Patel', '9824055555', 'rakesh.p@example.com', 'RP Constructions', 'Vadodara', 'referral', null, 'high', 'won', 12, 1500000],
            [$rahul, null, 'Neha', 'Shah', '9824066666', null, null, 'Surat', 'walk_in', null, 'medium', 'lost', 18, 90000],
            [$rahul, null, 'Farhan', 'Qureshi', '9824077777', 'farhan.q@example.com', 'FQ Exports', 'Ahmedabad', 'google_ads', null, 'medium', 'follow_up', 9, 300000],
            [$priya, null, 'Sneha', 'Iyer', '9898011111', 'sneha.iyer@example.com', 'Iyer & Co', 'Ahmedabad', 'referral', null, 'high', 'meeting_scheduled', 2, 450000],
            [$priya, null, 'Vikram', 'Singh', '9898022222', 'vikram.s@example.com', null, 'Rajkot', 'phone_call', null, 'medium', 'contacted', 4, 150000],
            [$priya, null, 'Anjali', 'Rao', '9898033333', null, 'Rao Pharma', 'Ahmedabad', 'walk_in', null, 'urgent', 'negotiation', 7, 920000],
            [$priya, null, 'Deepak', 'Verma', '9898044444', 'deepak.v@example.com', null, 'Anand', 'website', $website, 'low', 'new', 1, null],
            [$priya, null, 'Meera', 'Kulkarni', '9898055555', 'meera.k@example.com', 'MK Interiors', 'Ahmedabad', 'referral', null, 'high', 'won', 21, 640000],
            [$priya, null, 'Rohit', 'Bansal', '9898066666', null, null, 'Surat', 'google_ads', null, 'medium', 'lost', 16, 70000],
            [$manager, null, 'Harsh', 'Trivedi', '9727011111', 'harsh.t@example.com', 'Trivedi Motors', 'Ahmedabad', 'referral', null, 'high', 'interested', 5, 1100000],
            [$manager, $rahul, 'Isha', 'Kapoor', '9727022222', 'isha.k@example.com', null, 'Ahmedabad', 'phone_call', null, 'medium', 'contacted', 2, 200000],
            [$manager, $priya, 'Nikhil', 'Chauhan', '9727033333', 'nikhil.c@example.com', 'Chauhan Steel', 'Mehsana', 'walk_in', null, 'high', 'follow_up', 10, 560000],
            [$manager, $priya, 'Tanvi', 'Pandya', '9727044444', null, null, 'Ahmedabad', 'referral', null, 'low', 'new', 0, null],
            [$admin, $manager, 'Gaurav', 'Malhotra', '9920011111', 'gaurav.m@example.com', 'Malhotra Group', 'Mumbai', 'referral', null, 'urgent', 'negotiation', 8, 2500000],
            [$admin, $rahul, 'Ritu', 'Agarwal', '9920022222', 'ritu.a@example.com', null, 'Ahmedabad', 'google_ads', null, 'medium', 'interested', 13, 180000],
            [$admin, $admin, 'Sanjay', 'Gupta', '9920033333', 'sanjay.g@example.com', 'Gupta Realty', 'Pune', 'other', null, 'low', 'contacted', 25, 400000],
        ];

        foreach ($rows as [$creator, $assignee, $first, $last, $phone, $email, $company, $city, $src, $campaign, $priority, $statusSlug, $age, $value]) {
            $lead = $as($creator, fn () => $leads->create([
                'first_name' => $first, 'last_name' => $last, 'phone' => $phone, 'email' => $email,
                'company_name' => $company, 'city' => $city, 'state' => in_array($city, ['Mumbai', 'Pune'], true) ? 'Maharashtra' : 'Gujarat',
                'country' => 'India', 'source_id' => $source[$src], 'campaign_id' => $campaign?->id,
                'priority' => $priority, 'estimated_value' => $value,
                'assigned_to' => $assignee?->id,
            ], $creator, true));

            $owner = $lead->assignee ?? $creator;

            if ($statusSlug !== 'new') {
                $as($owner, fn () => $leads->changeStatus($lead, $status[$statusSlug], $owner, $statusSlug === 'lost' ? $reasons['Budget issue'] : null));
            }

            $as($owner, fn () => $notes->create($lead, $owner, $this->noteFor($statusSlug, $first), NoteVisibility::Team));

            $this->backdate($lead, $age);
        }

        $as($manager, fn () => $notes->create(Lead::where('first_name', 'Suresh')->first(), $manager, 'Discussed pricing flexibility with Rahul; approve up to 5% discount if needed.', NoteVisibility::Management));
        $as($rahul, fn () => $notes->create(Lead::where('first_name', 'Amit')->first(), $rahul, 'Personal reminder: customer prefers WhatsApp in the evening.', NoteVisibility::Private));

        // Inbound (webhook-style) leads: no matching rule yet, so they stay unassigned.
        foreach ([['Karan', 'Thakkar', '9712011111', 'karan.t@example.com', 'Ahmedabad'], ['Divya', 'Menon', '9712022222', null, 'Vadodara']] as [$f, $l, $p, $e, $c]) {
            $result = $leads->createFromInbound([
                'first_name' => $f, 'last_name' => $l, 'phone' => $p, 'email' => $e, 'city' => $c, 'state' => 'Gujarat',
                'country' => 'India', 'source_id' => $source['website'], 'campaign_id' => $website->id,
            ], AssignmentType::Automatic, ['form' => 'website_contact', 'message' => 'Please call me back about home loan options.']);
            $this->backdate($result['lead'], 1);
        }

        // Campaign rule: Diwali Facebook leads rotate across the Ahmedabad team.
        if ($team) {
            LeadAssignmentRule::firstOrCreate(['name' => 'Diwali campaign → Ahmedabad round robin'], [
                'condition_type' => RuleCondition::Campaign, 'condition_value' => (string) $diwali->id,
                'assignment_type' => RuleAssignment::TeamRoundRobin, 'assigned_team_id' => $team->id,
                'priority' => 10, 'is_active' => true, 'created_by' => $admin->id, 'updated_by' => $admin->id,
            ]);
            LeadAssignmentRule::firstOrCreate(['name' => 'Referral leads → Mehul'], [
                'condition_type' => RuleCondition::Source, 'condition_value' => (string) $source['referral'],
                'assignment_type' => RuleAssignment::User, 'assigned_user_id' => $manager->id,
                'priority' => 20, 'is_active' => false, 'created_by' => $admin->id, 'updated_by' => $admin->id,
            ]);
        }

        foreach ([['Manish', 'Solanki', '9601011111', 'manish.s@example.com'], ['Heena', 'Parmar', '9601022222', 'heena.p@example.com'], ['Jay', 'Rathod', '9601033333', null]] as $i => [$f, $l, $p, $e]) {
            $result = $leads->createFromInbound([
                'first_name' => $f, 'last_name' => $l, 'phone' => $p, 'email' => $e, 'city' => 'Ahmedabad', 'state' => 'Gujarat',
                'country' => 'India', 'source_id' => $source['facebook'], 'campaign_id' => $diwali->id,
                'facebook_lead_id' => '99000000000'.($i + 1), 'facebook_campaign_id' => $diwali->external_id,
            ], AssignmentType::Facebook, ['full_name' => "{$f} {$l}", 'phone_number' => $p, 'loan_amount' => '25-50 lakh']);
            $this->backdate($result['lead'], $i);
        }

        // A flagged duplicate (same phone as Amit Desai, different formatting).
        $dup = $as($priya, fn () => $leads->create([
            'first_name' => 'Amit', 'last_name' => 'D.', 'phone' => '+91 98240 11111', 'city' => 'Ahmedabad',
            'source_id' => $source['phone_call'], 'priority' => 'medium',
        ], $priya, true));
        $this->backdate($dup, 0);

        $this->command?->info('Demo leads created: '.Lead::count());
    }

    private function backdate(Lead $lead, int $days): void
    {
        if ($days <= 0) {
            return;
        }

        $at = now()->subDays($days)->subHours(random_int(0, 8));
        DB::table('leads')->where('id', $lead->id)->update(['created_at' => $at]);
        DB::table('lead_enquiries')->where('lead_id', $lead->id)->update(['received_at' => $at, 'created_at' => $at]);
        DB::table('activities')->where('subject_type', $lead->getMorphClass())->where('subject_id', $lead->id)
            ->where('type', 'lead_created')->update(['created_at' => $at]);
    }

    private function noteFor(string $status, string $name): string
    {
        return match ($status) {
            'new' => "Fresh enquiry from {$name}. First call pending.",
            'contacted' => "Spoke to {$name}; shared brochure on email. Wants a callback next week.",
            'interested' => "{$name} is interested and asked for a detailed quotation.",
            'follow_up' => "{$name} requested a follow-up after checking with family.",
            'meeting_scheduled' => "Site visit planned with {$name}.",
            'negotiation' => "{$name} negotiating on price; comparing with one competitor.",
            'won' => "Deal closed with {$name}. Documents collected.",
            'lost' => "{$name} dropped due to budget constraints.",
            default => "Initial note for {$name}.",
        };
    }
}
