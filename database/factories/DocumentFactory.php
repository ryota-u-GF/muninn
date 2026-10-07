<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Document> */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'title' => '文書'.fake()->unique()->numberBetween(1, 999999),
            'body' => '本文',
            'kind' => 'document',
            'visibility' => 'shared',
            'created_by' => User::factory(),
        ];
    }

    public function minutes(string $date): static
    {
        return $this->state(fn () => ['kind' => 'minutes', 'meeting_date' => $date]);
    }

    public function private(): static
    {
        return $this->state(fn () => ['visibility' => 'private']);
    }
}
