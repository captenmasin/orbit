<?php

namespace App\Http\Controllers;

use App\Actions\SaveProject;
use App\Models\Project;
use App\Models\ProviderConnection;
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
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(Project::STATUSES)],
            'tag' => ['nullable', 'string', 'max:50'],
            'sort' => ['nullable', Rule::in(['name', 'name-desc', 'last-commit'])],
        ]);
        $query = Project::with('tags')->withCount(['repositories', 'folders'])->withLatestCommit();
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

        return $this->render($request, 'Dashboard', [
            'projects' => $query->simplePaginate(25)->withPath(route('workspace', absolute: false))->withQueryString(),
            'statuses' => Project::STATUSES,
            'filters' => ['q' => $filters['q'] ?? '', 'status' => $filters['status'] ?? '', 'tag' => $filters['tag'] ?? '', 'sort' => $filters['sort'] ?? 'name'],
            'tags' => Tag::whereIn('id', fn ($query) => $query->select('tag_id')->from('project_tag'))->orderBy('name')->pluck('name'),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->render($request, 'CreateProject', ['statuses' => Project::STATUSES]);
    }

    public function show(Request $request, Project $project): Response
    {
        $project->load(['tags', 'repositories', 'folders', 'links', 'documents', 'boardColumns.tasks', 'secrets:id,project_id,environment,name,service,description,management_url,revision,updated_at']);

        return $this->render($request, 'ShowProject', [
            'selectedProject' => $project,
            'connections' => fn () => ProviderConnection::orderBy('label')->get(),
            'activity' => fn () => $project->repositories()->with(['providerConnection', 'providerSnapshots'])->get(),
            'inspection' => fn () => $project->folders()->with('packageRoots')->get(),
        ]);
    }

    public function edit(Request $request, Project $project): Response
    {
        return $this->render($request, 'EditProject', [
            'selectedProject' => $project->load(['tags', 'repositories', 'folders', 'links']),
            'statuses' => Project::STATUSES,
        ]);
    }

    public function connections(Request $request): Response
    {
        return $this->render($request, 'Connections', [
            'connections' => ProviderConnection::orderBy('label')->get(),
            'mcp' => config('nativephp-internal.running') ? [
                'command' => PHP_BINARY,
                'args' => [base_path('artisan'), 'mcp:start', 'orbit'],
                'env' => [
                    'DB_CONNECTION' => 'sqlite',
                    'DB_DATABASE' => DB::connection()->getDatabaseName(),
                    'NATIVEPHP_RUNNING' => 'false',
                ],
            ] : null,
        ]);
    }

    public function backups(Request $request): Response
    {
        return $this->render($request, 'Backups');
    }

    public function reorder(Request $request): RedirectResponse
    {
        $data = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['required', 'uuid', 'distinct']]);
        DB::transaction(function () use ($data): void {
            $ids = Project::pluck('id')->all();
            if (count($ids) !== count($data['ids']) || array_diff($ids, $data['ids'])) {
                throw ValidationException::withMessages(['ids' => 'The project list changed. Reload it before reordering.']);
            }
            foreach ($data['ids'] as $position => $id) {
                Project::whereKey($id)->update(['position' => $position]);
            }
        });

        return back();
    }

    private function render(Request $request, string $page, array $props = []): Response
    {
        $response = Inertia::render($page, $props)->toResponse($request);
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
        Storage::disk('local')->delete(array_column($project->asset_files ?? [], 'path'));
        Storage::disk('local')->deleteDirectory('project-assets/'.$project->id);

        return to_route('workspace')->with('message', 'Project removed');
    }
}
