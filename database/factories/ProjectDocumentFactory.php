<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProjectDocument> */
class ProjectDocumentFactory extends Factory
{
    public function definition(): array
    {
        return ['project_id' => Project::factory(), 'title' => fake()->sentence(3), 'body' => fake()->paragraph(), 'position' => 0];
    }
}
