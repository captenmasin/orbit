<?php

namespace App\Mcp\Tools;

use App\Actions\SaveProject;
use App\Http\Controllers\WorkspaceController;
use App\Mcp\LocalUpload;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Create, update, delete, or reorder Orbit projects. Update fields are optional; supplied tags, repositories, folders, and links replace their full respective lists. Read the project first to retain IDs and supply its current revision.')]
#[IsDestructive]
class ManageProjectTool extends Tool
{
    public function handle(Request $request, SaveProject $save): Response
    {
        $target = $request->validate([
            'action' => ['required', Rule::in(['create', 'update', 'delete', 'reorder'])],
            'project_id' => ['required_unless:action,create,reorder', 'prohibited_if:action,create,reorder', 'uuid'],
            'revision' => ['required_unless:action,create,reorder', 'prohibited_if:action,create,reorder', 'integer', 'min:1'],
            'ids' => ['required_if:action,reorder', 'prohibited_unless:action,reorder', 'array'],
            'icon_file_path' => ['sometimes', 'string', 'max:4096'],
        ]);

        if ($target['action'] === 'reorder') {
            app(WorkspaceController::class)->reorder(HttpRequest::create('/', 'PUT', ['ids' => $target['ids']]));

            return Response::json(['ids' => Project::orderBy('position')->pluck('id')->all()]);
        }

        if ($target['action'] === 'delete') {
            $project = Project::findOrFail($target['project_id']);
            app(WorkspaceController::class)->destroy(HttpRequest::create('/', 'DELETE', ['revision' => $target['revision']]), $project);

            return Response::json(['deleted' => true, 'project_id' => $project->id]);
        }

        $project = $target['action'] === 'update' ? Project::findOrFail($target['project_id']) : null;
        $input = $request->all();
        unset($input['action'], $input['project_id'], $input['ids'], $input['icon_file_path']);
        if ($project) {
            $input = array_replace(['name' => $project->name, 'status' => $project->status, 'description' => $project->description], $input);
        }
        foreach (['repositories', 'folders', 'links'] as $key) {
            if (isset($input[$key]) && is_array($input[$key])) {
                $input[$key] = array_map(function (mixed $entry): mixed {
                    if (is_array($entry)) {
                        $entry['id'] ??= (string) Str::uuid7();
                    }

                    return $entry;
                }, $input[$key]);
            }
        }
        if (isset($target['icon_file_path'])) {
            $input['icon_file'] = LocalUpload::fromPath($target['icon_file_path'], 'icon_file_path', 2097152);
        }

        $saved = $save->handle($input, $project?->id);

        return Response::json(['id' => $saved->id, 'revision' => $saved->revision, 'name' => $saved->name, 'status' => $saved->status]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['create', 'update', 'delete', 'reorder'])->required(),
            'project_id' => $schema->string()->description('Required for update or delete.'),
            'revision' => $schema->integer()->description('Current project revision; required for update or delete.'),
            'ids' => $schema->array()->items($schema->string())->description('Complete list of project IDs in the new order; required for reorder.'),
            'name' => $schema->string(),
            'status' => $schema->string()->enum(Project::STATUSES),
            'description' => $schema->string()->nullable(),
            'icon_type' => $schema->string()->enum(['initials', 'emoji', 'image']),
            'icon_emoji' => $schema->string()->nullable(),
            'icon_file_path' => $schema->string()->description('Absolute local image path, when setting an image icon.'),
            'tags' => $schema->array()->items($schema->string())->description('Complete replacement list of tag names.'),
            'repositories' => $schema->array()->items($schema->object([
                'id' => $schema->string()->description('Existing ID for updates; omit for a new repository.'),
                'name' => $schema->string()->nullable(),
                'remote_url' => $schema->string()->required(),
            ]))->description('Complete replacement list of repositories.'),
            'folders' => $schema->array()->items($schema->object([
                'id' => $schema->string()->description('Existing ID for updates; omit for a new folder.'),
                'path' => $schema->string()->required(),
                'repository_id' => $schema->string()->nullable(),
            ]))->description('Complete replacement list of local project folders.'),
            'links' => $schema->array()->items($schema->object([
                'id' => $schema->string()->description('Existing ID for updates; omit for a new link.'),
                'label' => $schema->string()->required(),
                'url' => $schema->string()->required(),
                'category' => $schema->string()->nullable(),
                'description' => $schema->string()->nullable(),
            ]))->description('Complete replacement list of project links.'),
        ];
    }
}
