<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectFolder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectFolder>
 */
class ProjectFolderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(), 'path' => '/missing/'.fake()->uuid(),
        ];
    }
}
