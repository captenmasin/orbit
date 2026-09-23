<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List Orbit projects with their status, description, and local folder availability.')]
class ReadWorkspaceTool extends Tool
{
    public function handle(Request $request): Response
    {
        return Response::json(Project::with('folders:id,project_id,path')->orderBy('position')->get(['id', 'name', 'status', 'description'])
            ->map(fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
                'status' => $project->status,
                'description' => $project->description,
                'has_local_copy' => $project->folders->contains(fn ($folder): bool => is_dir($folder->path)),
            ])->all());
    }
}
