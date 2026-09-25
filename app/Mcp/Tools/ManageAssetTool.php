<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\ProjectAssetController;
use App\Mcp\LocalUpload;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Upload, rename, move, or delete a project asset; create, rename, move, or delete an asset folder. Uploads use an absolute readable local file path and the current project revision.')]
#[IsDestructive]
class ManageAssetTool extends Tool
{
    public function handle(Request $request): Response
    {
        $target = $request->validate([
            'project_id' => ['required', 'uuid'],
            'action' => ['required', Rule::in(['file.upload', 'file.move', 'file.delete', 'folder.save', 'folder.delete'])],
            'revision' => ['required', 'integer', 'min:1'],
            'id' => ['required_if:action,file.move,file.delete,folder.delete', 'nullable', 'uuid'],
            'file_path' => ['required_if:action,file.upload', 'string', 'max:4096'],
        ]);
        $project = Project::findOrFail($target['project_id']);
        $controller = app(ProjectAssetController::class);
        $input = HttpRequest::create('/', 'POST', $request->all());
        if ($target['action'] === 'file.upload') {
            $input->files->set('files', [LocalUpload::fromPath($target['file_path'], 'file_path')]);
        }
        match ($target['action']) {
            'file.upload' => $controller->store($input, $project),
            'file.move' => $controller->move($input, $project, $target['id']),
            'file.delete' => $controller->destroy($input, $project, $target['id']),
            'folder.save' => $controller->saveFolder($input, $project, $target['id'] ?? null),
            'folder.delete' => $controller->destroyFolder($input, $project, $target['id']),
        };

        $current = $project->fresh();

        return Response::json(['project_revision' => $current->revision, 'assets' => $current->assets, 'asset_folders' => $current->asset_folders ?? []]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()->required(),
            'action' => $schema->string()->enum(['file.upload', 'file.move', 'file.delete', 'folder.save', 'folder.delete'])->required(),
            'revision' => $schema->integer()->description('Current project revision.')->required(),
            'id' => $schema->string()->description('Asset or folder ID for edit or delete.'),
            'file_path' => $schema->string()->description('Absolute local file path for file.upload.'),
            'folder_id' => $schema->string()->nullable()->description('Asset destination folder; null is the root.'),
            'name' => $schema->string()->description('New asset or folder name.'),
            'parent_id' => $schema->string()->nullable()->description('Parent folder for folder.save.'),
        ];
    }
}
