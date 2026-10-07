<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => Str::lower(Str::random(10)).'@example.test',
            'role' => Role::Employee,
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => Role::Admin]);
    }

    public function contractor(): static
    {
        return $this->state(fn () => ['role' => Role::Contractor]);
    }

    public function tv(): static
    {
        return $this->state(fn () => ['role' => Role::Tv]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
