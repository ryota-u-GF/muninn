<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Project> */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => '案件'.fake()->unique()->numberBetween(1, 99999),
            'sort_order' => fake()->numberBetween(1, 100),
        ];
    }

    public function archived(): static
    {
        return $this->state(fn () => ['archived_at' => now()]);
    }
}
