<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ProjectImportanceController extends Controller
{
    public function update(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['links', 'documents'])],
            'id' => ['required', 'uuid'],
            'important' => ['required', 'boolean'],
            'revision' => ['required', 'integer', 'min:1'],
        ]);

        try {
            DB::transaction(function () use ($project, $data): void {
                $item = $project->{$data['kind']}()->findOrFail($data['id']);
                if (! Project::whereKey($project->id)->where('revision', $data['revision'])->update(['revision' => DB::raw('revision + 1')])) {
                    throw new ConflictHttpException('This project changed. Reload it before changing important items.');
                }
                $item->important = $data['important'];
                if ($item instanceof ProjectDocument) {
                    $item->revision++;
                }
                $item->save();
            });
        } catch (ConflictHttpException $exception) {
            if ($request->header('X-Inertia')) {
                throw ValidationException::withMessages(['revision' => $exception->getMessage()]);
            }
            throw $exception;
        }

        return back();
    }
}
