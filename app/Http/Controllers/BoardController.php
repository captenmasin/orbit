<?php

namespace App\Http\Controllers;

use App\Actions\SaveBoard;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class BoardController extends Controller
{
    public function preview(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate(['description' => ['nullable', 'string', 'max:10000']]);

        return response()->json(['html' => Task::renderDescription($data['description'] ?? '')]);
    }

    public function download(Project $project, Task $task, string $attachment): BinaryFileResponse
    {
        abort_unless($task->column->project_id === $project->id, 404);
        $file = collect($task->attachment_files ?? [])->firstWhere('id', $attachment);
        abort_unless($file && Storage::disk('local')->exists($file['path']), 404);

        return response()->download(Storage::disk('local')->path($file['path']), $file['name'], [
            'Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "default-src 'none'; sandbox",
        ])->setPrivate();
    }

    public function update(Request $request, Project $project, SaveBoard $save): RedirectResponse
    {
        try {
            $save->handle($project, $request->all());
        } catch (ConflictHttpException $exception) {
            if ($request->header('X-Inertia')) {
                throw ValidationException::withMessages(['revision' => $exception->getMessage()]);
            }

            throw $exception;
        }

        return to_route('projects.show', $project);
    }
}
