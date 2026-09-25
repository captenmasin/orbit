<?php

namespace App\Mcp\Tools;

use App\Actions\SaveBoard;
use App\Mcp\LocalUpload;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Create, edit, move, bulk-add, or delete board columns and tasks. Attachment paths must be absolute readable local files. Supply the current project revision.')]
#[IsDestructive]
class ManageBoardTool extends Tool
{
    public function handle(Request $request, SaveBoard $save): Response
    {
        $target = $request->validate([
            'project_id' => ['required', 'uuid'],
            'attachment_paths' => ['sometimes', 'array', 'list', 'max:10'],
            'attachment_paths.*' => ['required', 'string', 'max:4096'],
        ]);
        $project = Project::findOrFail($target['project_id']);
        $input = $request->all();
        unset($input['project_id'], $input['attachment_paths']);
        if (isset($target['attachment_paths'])) {
            $input['attachments'] = array_map(
                fn (string $path) => LocalUpload::fromPath($path, 'attachment_paths'),
                $target['attachment_paths'],
            );
        }
        $save->handle($project, $input);

        $current = $project->fresh()->load('boardColumns.tasks');

        return Response::json([
            'project_revision' => $current->revision,
            'board' => $current->boardColumns->map(fn ($column): array => [
                'id' => $column->id, 'name' => $column->name, 'position' => $column->position,
                'tasks' => $column->tasks->map->only(['id', 'title', 'description', 'position', 'attachments']),
            ]),
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()->required(),
            'action' => $schema->string()->enum(['column.save', 'column.move', 'column.delete', 'task.save', 'task.bulk', 'task.move', 'task.delete'])->required(),
            'revision' => $schema->integer()->description('Current project revision.')->required(),
            'id' => $schema->string()->description('Existing column or task ID for edit, move, or delete.'),
            'name' => $schema->string()->description('Column name for column.save.'),
            'title' => $schema->string()->description('Task title for task.save.'),
            'description' => $schema->string()->nullable(),
            'column_id' => $schema->string()->description('Target column ID for task actions.'),
            'destination_id' => $schema->string()->description('Destination column when deleting a nonempty column.'),
            'position' => $schema->integer()->description('Zero-based destination for a move.'),
            'titles' => $schema->array()->items($schema->string())->description('Task titles for task.bulk.'),
            'attachment_paths' => $schema->array()->items($schema->string())->description('Absolute local paths to files to attach to a task.'),
            'removed_attachment_ids' => $schema->array()->items($schema->string()),
        ];
    }
}
