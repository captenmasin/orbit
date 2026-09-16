<?php

namespace App\Http\Controllers;

use App\Actions\SaveProject;
use App\Models\Project;
use App\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class WorkspaceController extends Controller
{
    public function index(Request $request, ?Project $project = null): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(Project::STATUSES)],
            'tag' => ['nullable', 'string', 'max:50'],
            'sort' => ['nullable', Rule::in(['name', 'name-desc', 'last-commit'])],
        ]);
        $query = Project::with('tags')->withCount(['repositories', 'folders'])->withMax('folders as last_commit_at', 'last_commit_at');
        if ($search = $filters['q'] ?? null) {
            $search = mb_strtolower($search);
            $query->where(fn ($query) => $query->whereRaw('instr(lower(name), ?) > 0', [$search])
                ->orWhereRaw('instr(lower(description), ?) > 0', [$search])
                ->orWhereHas('tags', fn ($tags) => $tags->whereRaw('instr(lower(name), ?) > 0', [$search])));
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }
        if ($tag = $filters['tag'] ?? null) {
            $query->whereHas('tags', fn ($tags) => $tags->where('name', mb_strtolower(trim($tag))));
        }
        if (($filters['sort'] ?? 'name') === 'last-commit') {
            $query->orderByDesc('last_commit_at');
        }
        $query->orderByRaw('name COLLATE NOCASE '.(($filters['sort'] ?? null) === 'name-desc' ? 'desc' : 'asc'))->orderBy('id');
        $project?->load(['tags', 'repositories', 'folders', 'links', 'boardColumns.tasks'])->loadMax('folders as last_commit_at', 'last_commit_at');
        $response = Inertia::render('Workspace', [
            'projects' => $query->simplePaginate(25)->withPath(route('workspace', absolute: false))->withQueryString(),
            'selectedProject' => $project,
            'inspection' => fn () => $project?->folders()->with('packageRoots')->get() ?? [],
            'statuses' => Project::STATUSES,
            'creating' => $request->routeIs('projects.create'),
            'filters' => ['q' => $filters['q'] ?? '', 'status' => $filters['status'] ?? '', 'tag' => $filters['tag'] ?? '', 'sort' => $filters['sort'] ?? 'name'],
            'tags' => Tag::whereIn('id', fn ($query) => $query->select('tag_id')->from('project_tag'))->orderBy('name')->pluck('name'),
            'native' => (bool) config('nativephp-internal.running'),
        ])->toResponse($request);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    public function store(Request $request, SaveProject $save): RedirectResponse
    {
        $project = $save->handle($request->all());

        return to_route('projects.show', $project);
    }

    public function update(Request $request, Project $project, SaveProject $save): RedirectResponse
    {
        try {
            $save->handle($request->all(), $project->id);
        } catch (ConflictHttpException $exception) {
            if ($request->header('X-Inertia')) {
                throw ValidationException::withMessages(['revision' => $exception->getMessage()]);
            }

            throw $exception;
        }

        return to_route('projects.show', $project)->with('message', 'Saved');
    }

    public function destroy(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        $removed = DB::transaction(fn () => Project::whereKey($project->id)->where('revision', $data['revision'])->delete());
        if (! $removed) {
            $message = 'This project changed. Reload it before removing it.';
            if ($request->header('X-Inertia')) {
                throw ValidationException::withMessages(['revision' => $message]);
            }
            throw new ConflictHttpException($message);
        }
        if ($project->icon_path) {
            Storage::disk('local')->delete($project->icon_path);
        }
        Storage::disk('local')->deleteDirectory('task-attachments/'.$project->id);

        return to_route('workspace')->with('message', 'Project removed');
    }
}
