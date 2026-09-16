<?php

namespace App\Actions;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class SaveBoard
{
    public function handle(Project $project, array $input): void
    {
        $action = Validator::make($input, [
            'action' => ['required', Rule::in(['column.save', 'column.move', 'column.delete', 'task.save', 'task.move', 'task.delete'])],
        ])->validate()['action'];
        $rules = match ($action) {
            'column.save' => ['name' => ['required', 'string', 'max:100', 'regex:/\S/u']],
            'column.move' => ['position' => ['required', 'integer', 'min:0']],
            'column.delete' => ['destination_id' => ['nullable', 'uuid']],
            'task.save' => [
                'title' => ['required', 'string', 'max:255', 'regex:/\S/u'],
                'description' => ['nullable', 'string', 'max:10000'],
                'column_id' => ['required', 'uuid'],
                'attachments' => ['sometimes', 'array', 'max:10'],
                'attachments.*' => ['required', 'file', 'max:10240'],
                'removed_attachment_ids' => ['sometimes', 'array', 'max:10'],
                'removed_attachment_ids.*' => ['required', 'uuid', 'distinct'],
            ],
            'task.move' => ['column_id' => ['required', 'uuid'], 'position' => ['required', 'integer', 'min:0']],
            'task.delete' => [],
        };
        $data = Validator::make($input, [
            ...$rules,
            'revision' => ['required', 'integer', 'min:1'],
            'id' => [str_ends_with($action, '.save') ? 'nullable' : 'required', 'uuid'],
        ])->validate();

        $newFiles = [];
        $removedFiles = [];
        try {
            DB::transaction(function () use ($project, $action, $data, &$newFiles, &$removedFiles) {
                if (! Project::whereKey($project->id)->where('revision', $data['revision'])
                    ->update(['revision' => DB::raw('revision + 1')])) {
                    throw new ConflictHttpException('This project changed. Reload the board before trying again.');
                }

                match ($action) {
                    'column.save' => $this->saveColumn($project, $data),
                    'column.move' => $this->moveColumn($project, $data),
                    'column.delete' => $this->deleteColumn($project, $data),
                    'task.save' => $this->saveTask($project, $data, $newFiles, $removedFiles),
                    'task.move' => $this->moveTask($project, $data),
                    'task.delete' => $this->deleteTask($project, $data, $removedFiles),
                };
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($newFiles);
            throw $exception;
        }
        Storage::disk('local')->delete($removedFiles);
    }

    private function saveColumn(Project $project, array $data): void
    {
        if ($data['id'] ?? null) {
            $project->boardColumns()->findOrFail($data['id'])->update(['name' => trim($data['name'])]);
        } else {
            $project->boardColumns()->create(['name' => trim($data['name']), 'position' => $project->boardColumns()->count()]);
        }
    }

    private function moveColumn(Project $project, array $data): void
    {
        $column = $project->boardColumns()->findOrFail($data['id']);
        $ids = $project->boardColumns()->whereKeyNot($column->id)->pluck('id')->all();
        $this->insertAt($ids, $column->id, (int) $data['position']);
        $this->reorder($project->boardColumns(), $ids);
    }

    private function deleteColumn(Project $project, array $data): void
    {
        $column = $project->boardColumns()->findOrFail($data['id']);
        $tasks = $column->tasks()->get();
        if ($tasks->isNotEmpty()) {
            $destination = $project->boardColumns()->whereKeyNot($column->id)->find($data['destination_id'] ?? '');
            if (! $destination) {
                throw ValidationException::withMessages(['destination_id' => 'Choose another column for the remaining tasks.']);
            }
            $position = $destination->tasks()->count();
            foreach ($tasks as $task) {
                $task->column()->associate($destination);
                $task->position = $position++;
                $task->save();
            }
        }
        $column->delete();
        $this->reorder($project->boardColumns(), $project->boardColumns()->pluck('id')->all());
    }

    private function saveTask(Project $project, array $data, array &$newFiles, array &$removedFiles): void
    {
        $column = $project->boardColumns()->findOrFail($data['column_id']);
        $attributes = ['title' => trim($data['title']), 'description' => $data['description'] ?? null];
        $task = empty($data['id']) ? new Task : $this->task($project, $data['id']);
        $files = collect($task->attachment_files ?? []);
        $removed = $data['removed_attachment_ids'] ?? [];
        if (array_diff($removed, $files->pluck('id')->all())) {
            throw ValidationException::withMessages(['removed_attachment_ids' => 'Choose attachments belonging to this task.']);
        }
        $retained = $files->whereNotIn('id', $removed)->values()->all();
        if (count($retained) + count($data['attachments'] ?? []) > 10) {
            throw ValidationException::withMessages(['attachments' => 'A task can have up to 10 attachments.']);
        }
        foreach ($data['attachments'] ?? [] as $file) {
            $path = $file->store('task-attachments/'.$project->id, 'local');
            if (! $path) {
                throw ValidationException::withMessages(['attachments' => 'An attachment could not be stored. Try again.']);
            }
            $newFiles[] = $path;
            $retained[] = ['id' => (string) Str::uuid7(), 'name' => $file->getClientOriginalName(), 'path' => $path, 'size' => $file->getSize()];
        }
        $removedFiles = $files->whereIn('id', $removed)->pluck('path')->all();
        $previous = $task->exists ? $task->column : null;
        $task->fill($attributes);
        $task->attachment_files = $retained;
        if ($previous?->id !== $column->id) {
            $task->column()->associate($column);
            $task->position = $column->tasks()->count();
        }
        $task->save();
        if ($previous && $previous->id !== $column->id) {
            $this->reorder($previous->tasks(), $previous->tasks()->pluck('id')->all());
        }
    }

    private function moveTask(Project $project, array $data): void
    {
        $task = $this->task($project, $data['id']);
        $previous = $task->column;
        $column = $project->boardColumns()->findOrFail($data['column_id']);
        $ids = $column->tasks()->whereKeyNot($task->id)->pluck('id')->all();
        $this->insertAt($ids, $task->id, (int) $data['position']);
        $task->column()->associate($column);
        $task->save();
        if ($previous->id !== $column->id) {
            $this->reorder($previous->tasks(), $previous->tasks()->pluck('id')->all());
        }
        $this->reorder($column->tasks(), $ids);
    }

    private function deleteTask(Project $project, array $data, array &$removedFiles): void
    {
        $task = $this->task($project, $data['id']);
        $column = $task->column;
        $removedFiles = array_column($task->attachment_files ?? [], 'path');
        $task->delete();
        $this->reorder($column->tasks(), $column->tasks()->pluck('id')->all());
    }

    private function task(Project $project, string $id): Task
    {
        return Task::whereHas('column', fn ($query) => $query->where('project_id', $project->id))->findOrFail($id);
    }

    private function insertAt(array &$ids, string $id, int $position): void
    {
        if ($position > count($ids)) {
            throw ValidationException::withMessages(['position' => 'Choose a position within this column or board.']);
        }
        array_splice($ids, $position, 0, [$id]);
    }

    private function reorder(HasMany $relation, array $ids): void
    {
        // ponytail: rewrite contiguous positions; use fractional positions if large boards make moves slow.
        foreach ($ids as $position => $id) {
            (clone $relation)->whereKey($id)->update(['position' => $position]);
        }
    }
}
