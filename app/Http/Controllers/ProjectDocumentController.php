<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ProjectDocumentController extends Controller
{
    public function preview(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate(['body' => ['nullable', 'string', 'max:50000']]);

        return response()->json(['html' => Task::renderDescription($data['body'] ?? '')]);
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['save', 'move', 'delete'])],
            'revision' => ['required', 'integer', 'min:1'],
            'id' => ['nullable', 'required_unless:action,save', 'uuid'],
            'document_revision' => ['nullable', 'required_with:id', 'integer', 'min:1'],
            'title' => ['required_if:action,save', 'string', 'max:255', 'regex:/\S/u'],
            'body' => ['nullable', 'string', 'max:50000'],
            'position' => ['required_if:action,move', 'integer', 'min:0'],
        ]);
        try {
            $documentId = DB::transaction(function () use ($project, $data): ?string {
                $document = empty($data['id']) ? null : $project->documents()->findOrFail($data['id']);
                if (($document && $document->revision !== (int) $data['document_revision']) || ! Project::whereKey($project->id)->where('revision', $data['revision'])->update(['revision' => DB::raw('revision + 1')])) {
                    throw new ConflictHttpException('This project changed. Reload the documents before trying again. Your draft is still here.');
                }
                if ($data['action'] === 'save') {
                    $attributes = ['title' => trim($data['title']), 'body' => $data['body'] ?? ''];
                    if ($document) {
                        $document->fill($attributes);
                        $document->revision++;
                        $document->save();
                    } else {
                        $document = $project->documents()->create([...$attributes, 'position' => ($project->documents()->max('position') ?? -1) + 1]);
                    }

                    return $document->id;
                }
                $ids = $project->documents()->whereKeyNot($document->id)->pluck('id')->all();
                if ($data['action'] === 'delete') {
                    $document->delete();
                } else {
                    if ($data['position'] > count($ids)) {
                        throw ValidationException::withMessages(['position' => 'Choose a position within the documents.']);
                    }
                    array_splice($ids, $data['position'], 0, [$document->id]);
                }
                foreach ($ids as $position => $id) {
                    $project->documents()->whereKey($id)->update(['position' => $position]);
                }

                return $data['action'] === 'delete' ? null : $document->id;
            });
        } catch (ConflictHttpException $exception) {
            if ($request->header('X-Inertia')) {
                throw ValidationException::withMessages(['revision' => $exception->getMessage()]);
            }
            throw $exception;
        }

        return to_route('projects.show', ['project' => $project, 'tab' => 'documents', 'document' => $documentId]);
    }
}
