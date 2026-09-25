<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'is_active' => true,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /** Requires RoleSeeder to have run. */
    public function role(string $slug): static
    {
        return $this->state(fn () => ['role_id' => Role::where('slug', $slug)->value('id')]);
    }

    public function superAdmin(): static
    {
        return $this->role('super_admin');
    }

    public function admin(): static
    {
        return $this->role('admin');
    }

    public function salesManager(): static
    {
        return $this->role('sales_manager');
    }

    public function salesExecutive(): static
    {
        return $this->role('sales_executive');
    }
}
