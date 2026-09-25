<?php

namespace Database\Factories;

use App\Enums\LeadPriority;
use App\Enums\MeetingLocationType;
use App\Enums\MeetingStatus;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\MeetingType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Creates meetings directly (bypassing MeetingService) for test setup.
 * Tests that exercise business rules should go through the service or HTTP.
 *
 * @extends Factory<Meeting>
 */
class MeetingFactory extends Factory
{
    public function definition(): array
    {
        $start = now()->addDay()->startOfHour();

        return [
            'meeting_number' => 'MTG-TEST-'.Str::upper(Str::random(8)),
            'lead_id' => fn () => Lead::factory(),
            'host_user_id' => fn (array $a) => $a['lead_id'] ? Lead::find($a['lead_id'])?->assigned_to : null,
            'team_id' => fn (array $a) => $a['lead_id'] ? Lead::find($a['lead_id'])?->team_id : null,
            'meeting_type_id' => fn () => MeetingType::query()->where('slug', 'office_meeting')->value('id'),
            'title' => fake()->sentence(3),
            'start_at' => $start,
            'end_at' => $start->copy()->addHour(),
            'timezone' => 'Asia/Kolkata',
            'location_type' => MeetingLocationType::Office,
            'status' => MeetingStatus::Scheduled,
            'priority' => LeadPriority::Medium,
            'reminder_offsets' => [],
        ];
    }

    public function forLead(Lead $lead): static
    {
        return $this->state(fn () => ['lead_id' => $lead->id, 'host_user_id' => $lead->assigned_to, 'team_id' => $lead->team_id]);
    }

    public function between(\DateTimeInterface $start, \DateTimeInterface $end): static
    {
        return $this->state(fn () => ['start_at' => $start, 'end_at' => $end]);
    }

    public function status(MeetingStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
