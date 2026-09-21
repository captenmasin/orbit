<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectSecret;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectSecret>
 */
class ProjectSecretFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'environment' => 'Default',
            'name' => fake()->unique()->regexify('[A-Z_]{12}'),
            'ciphertext' => 'fixture-ciphertext',
        ];
    }
}
