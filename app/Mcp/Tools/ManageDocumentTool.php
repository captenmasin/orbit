<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\ProjectDocumentController;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Create, edit, move, or delete a project document using the current project revision and, for existing documents, its document revision.')]
#[IsDestructive]
class ManageDocumentTool extends Tool
{
    public function handle(Request $request): Response
    {
        $target = $request->validate(['project_id' => ['required', 'uuid']]);
        $project = Project::findOrFail($target['project_id']);
        app(ProjectDocumentController::class)->update(HttpRequest::create('/', 'PUT', $request->all()), $project);

        return Response::json([
            'project_revision' => $project->fresh()->revision,
            'documents' => $project->documents()->get(['id', 'revision', 'position', 'title'])->toArray(),
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()->required(),
            'action' => $schema->string()->enum(['save', 'move', 'delete'])->required(),
            'revision' => $schema->integer()->description('Current project revision.')->required(),
            'id' => $schema->string()->description('Existing document ID for edit, move, or delete.'),
            'document_revision' => $schema->integer()->description('Current revision of an existing document.'),
            'title' => $schema->string()->description('Required for save.'),
            'body' => $schema->string()->nullable(),
            'position' => $schema->integer()->description('Zero-based destination for move.'),
        ];
    }
}
