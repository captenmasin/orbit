<?php

namespace Database\Factories;

use App\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProviderSnapshotFactory extends Factory
{
    public function definition(): array
    {
        return ['repository_id' => Repository::factory(), 'resource' => 'issues'];
    }
}
