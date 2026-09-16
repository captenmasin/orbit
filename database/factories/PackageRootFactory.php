<?php

namespace Database\Factories;

use App\Models\PackageRoot;
use App\Models\ProjectFolder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PackageRoot>
 */
class PackageRootFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_folder_id' => ProjectFolder::factory(), 'relative_path' => 'packages/'.fake()->uuid(),
        ];
    }
}
