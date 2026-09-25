<?php

namespace Database\Factories;

use App\Enums\LeadPriority;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates leads directly (bypassing LeadService) for test setup. Tests that
 * exercise business rules should go through the service or HTTP layer.
 *
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    private static int $sequence = 0;

    public function definition(): array
    {
        $first = fake()->firstName();
        $last = fake()->lastName();
        $phone = '9'.fake()->unique()->numerify('#########');

        return [
            'first_name' => $first,
            'last_name' => $last,
            'full_name' => fn (array $attributes) => trim($attributes['first_name'].' '.$attributes['last_name']),
            'email' => strtolower(fake()->unique()->safeEmail()),
            'phone' => $phone,
            'normalized_phone' => '91'.$phone,
            'city' => 'Ahmedabad',
            'state' => 'Gujarat',
            'country' => 'India',
            'priority' => LeadPriority::Medium,
            'lead_number' => sprintf('TS-%s-%06d', now()->format('Y'), ++self::$sequence),
            'source_id' => fn () => LeadSource::query()->value('id'),
            'status_id' => fn () => LeadStatus::defaultStatus()?->id,
        ];
    }

    public function assignedTo(User $user): static
    {
        return $this->state(fn () => ['assigned_to' => $user->id, 'team_id' => $user->team_id]);
    }

    public function status(string $slug): static
    {
        return $this->state(fn () => ['status_id' => LeadStatus::where('slug', $slug)->value('id')]);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['deleted_at' => now()]);
    }
}
