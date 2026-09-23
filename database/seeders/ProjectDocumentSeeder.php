<?php

namespace Database\Seeders;

use App\Models\Project;
use Illuminate\Database\Seeder;

class ProjectDocumentSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Project::doesntHave('documents')->get() as $project) {
            $project->documents()->create(['title' => 'Getting started', 'body' => "## Setup\n\nDocument this project's setup and operating instructions here.", 'position' => 0]);
        }
    }
}
