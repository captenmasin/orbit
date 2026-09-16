<?php

namespace Database\Factories;

use App\Models\BoardColumn;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'board_column_id' => BoardColumn::factory(), 'title' => fake()->sentence(4),
            'description' => fake()->paragraph(), 'position' => 0,
        ];
    }
}
