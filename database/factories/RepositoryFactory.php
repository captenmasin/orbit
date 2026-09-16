<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Repository>
 */
class RepositoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(), 'name' => fake()->word(), 'remote_url' => 'https://github.com/example/'.fake()->unique()->slug(2).'.git',
        ];
    }
}
