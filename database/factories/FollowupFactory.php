<?php

namespace Database\Factories;

use App\Enums\FollowupStatus;
use App\Enums\LeadPriority;
use App\Models\Followup;
use App\Models\FollowupType;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates follow-ups directly (bypassing FollowupService) for test setup.
 * Tests that exercise business rules should go through the service or HTTP.
 *
 * @extends Factory<Followup>
 */
class FollowupFactory extends Factory
{
    public function definition(): array
    {
        return [
            'lead_id' => fn () => Lead::factory(),
            'assigned_to' => fn (array $a) => Lead::find($a['lead_id'])?->assigned_to,
            'team_id' => fn (array $a) => Lead::find($a['lead_id'])?->team_id,
            'followup_type_id' => fn () => FollowupType::query()->where('slug', 'call')->value('id'),
            'title' => fake()->sentence(4),
            'scheduled_at' => now()->addDay()->startOfMinute(),
            'timezone' => 'Asia/Kolkata',
            'status' => FollowupStatus::Pending,
            'priority' => LeadPriority::Medium,
            'reminder_minutes_before' => 15,
        ];
    }

    public function forLead(Lead $lead): static
    {
        return $this->state(fn () => ['lead_id' => $lead->id, 'assigned_to' => $lead->assigned_to, 'team_id' => $lead->team_id]);
    }

    public function at(\DateTimeInterface $at): static
    {
        return $this->state(fn () => ['scheduled_at' => $at]);
    }

    public function status(FollowupStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
