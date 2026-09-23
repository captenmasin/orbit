<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Read one Orbit project, including links, documents, tasks, asset names, and secret metadata. Never returns secret values.')]
class ReadProjectTool extends Tool
{
    public function handle(Request $request): Response
    {
        $project = Project::with(['tags', 'repositories', 'folders', 'links', 'documents', 'boardColumns.tasks', 'secrets:id,project_id,environment,name,service,description,management_url'])
            ->findOrFail($request->get('project_id'));

        return Response::json([
            'id' => $project->id,
            'name' => $project->name,
            'status' => $project->status,
            'description' => $project->description,
            'tags' => $project->tags->pluck('name'),
            'repositories' => $project->repositories->map->only(['name', 'remote_url']),
            'folders' => $project->folders->map->only(['path', 'repository_id']),
            'links' => $project->links->map->only(['label', 'url', 'category', 'description']),
            'documents' => $project->documents->map->only(['title', 'body']),
            'board' => $project->boardColumns->map(fn ($column): array => [
                'name' => $column->name,
                'tasks' => $column->tasks->map->only(['title', 'description']),
            ]),
            'assets' => array_map(fn (array $file): array => ['name' => $file['name'], 'size' => $file['size'], 'folder_id' => $file['folder_id'] ?? null], $project->asset_files ?? []),
            'asset_folders' => $project->asset_folders ?? [],
            'secrets' => $project->secrets->map->only(['name', 'environment', 'service', 'description', 'management_url']),
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['project_id' => $schema->string()->description('Orbit project ID from read_workspace')->required()];
    }
}
