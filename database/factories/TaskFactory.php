<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Task> */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'title' => 'タスク'.fake()->unique()->numberBetween(1, 999999),
            'description' => '説明',
            'status' => 'todo',
            'visibility' => 'shared',
            'created_by' => User::factory(),
            'sort_order' => fake()->numberBetween(1, 1000),
            'created_via' => 'manual',
        ];
    }

    public function private(): static
    {
        return $this->state(fn () => ['visibility' => 'private']);
    }
}
