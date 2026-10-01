<?php

namespace Database\Factories;

use App\Enums\BatchStatus;
use App\Models\Batch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Creates batches directly (bypassing BatchService) for test setup.
 * Tests that exercise business rules should go through the service or HTTP.
 *
 * @extends Factory<Batch>
 */
class BatchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'batch_number' => 'BAT-TEST-'.Str::upper(Str::random(8)),
            'name' => fake()->words(3, true),
            'description' => null,
            'status' => BatchStatus::Active,
        ];
    }

    public function archived(): static
    {
        return $this->state(['status' => BatchStatus::Archived]);
    }
}
