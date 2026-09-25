<?php

namespace Database\Factories;

use App\Enums\CallChannel;
use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Models\Call;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Creates calls directly (bypassing the telephony services) for test setup.
 * Tests that exercise lifecycle rules go through the services or webhooks.
 *
 * @extends Factory<Call>
 */
class CallFactory extends Factory
{
    public function definition(): array
    {
        return [
            'call_number' => 'CALL-TEST-'.Str::upper(Str::random(8)),
            'client_reference' => (string) Str::uuid(),
            'lead_id' => fn () => Lead::factory(),
            'agent_user_id' => fn (array $a) => $a['lead_id'] ? Lead::find($a['lead_id'])?->assigned_to : null,
            'team_id' => fn (array $a) => $a['agent_user_id'] ? User::find($a['agent_user_id'])?->team_id : null,
            'provider' => 'fake',
            'provider_call_id' => 'FAKE-'.Str::upper(Str::random(12)),
            'direction' => CallDirection::Outbound,
            'channel' => CallChannel::Pstn,
            'contact_field' => 'phone',
            'to_number' => '919800000000',
            'to_number_normalized' => '919800000000',
            'customer_number_normalized' => '919800000000',
            'status' => CallStatus::Initiated,
            'started_at' => now(),
        ];
    }

    public function completed(int $talkSeconds = 120): static
    {
        return $this->state(fn () => [
            'status' => CallStatus::Completed,
            'answered_at' => now()->subSeconds($talkSeconds),
            'ended_at' => now(),
            'talk_duration_seconds' => $talkSeconds,
            'total_duration_seconds' => $talkSeconds + 10,
            'requires_disposition' => true,
        ]);
    }

    public function status(CallStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function by(User $agent): static
    {
        return $this->state(fn () => ['agent_user_id' => $agent->id, 'team_id' => $agent->team_id]);
    }
}
