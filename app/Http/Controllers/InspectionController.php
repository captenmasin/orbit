<?php

namespace App\Http\Controllers;

use App\Actions\ProbeRuntimes;
use App\Actions\QueueInspection;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InspectionController extends Controller
{
    public function refresh(Request $request, Project $project, QueueInspection $queue): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['all', 'folder', 'root'])],
            'id' => ['nullable', 'uuid', 'required_unless:kind,all'], 'only_stale' => ['sometimes', 'boolean'],
        ]);
        $folders = $project->folders()->with('packageRoots')->get();
        $targets = match ($data['kind']) {
            'all' => $folders->flatMap(fn ($folder) => [$folder, ...$folder->packageRoots]),
            'folder' => collect([$folders->firstWhere('id', $data['id'])]),
            'root' => collect([$folders->flatMap->packageRoots->firstWhere('id', $data['id'])]),
        };
        abort_if($targets->contains(null), 404);
        $queued = $targets->filter(fn ($target): bool => $queue->handle($target, $data['only_stale'] ?? false))->count();

        return response()->json(['queued' => $queued]);
    }

    public function roots(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['save', 'delete'])], 'folder_id' => ['required', 'uuid'],
            'id' => ['nullable', 'uuid'], 'revision' => ['nullable', 'integer', 'min:1', 'required_with:id'],
            'path' => ['required_if:action,save', 'nullable', 'string', 'max:4096', 'starts_with:/', 'not_regex:/\x00/'],
            'executable_overrides' => ['sometimes', 'array:'.implode(',', ProbeRuntimes::TOOLS)],
            'executable_overrides.*' => ['nullable', 'string', 'max:4096', 'starts_with:/', 'not_regex:/\x00/'],
        ]);
        $folder = $project->folders()->findOrFail($data['folder_id']);
        $root = isset($data['id']) ? $folder->packageRoots()->findOrFail($data['id']) : null;
        abort_if($data['action'] === 'delete' && ! $root, 404);
        $relative = null;
        if ($data['action'] === 'save') {
            $base = realpath($folder->path);
            $path = realpath($data['path']);
            if (! $base || ! $path || ! is_dir($path) || ! is_readable($path)) {
                throw ValidationException::withMessages(['path' => 'Choose a readable folder.']);
            }
            if ($path !== $base && ! str_starts_with($path, rtrim($base, '/').'/')) {
                throw ValidationException::withMessages(['path' => 'Choose a root inside this linked folder, or link it as a separate folder.']);
            }
            $relative = $path === $base ? '.' : substr($path, strlen(rtrim($base, '/')) + 1);
        }
        DB::transaction(function () use ($folder, $root, $data, $relative): void {
            $currentFolder = $folder->fresh();
            $currentRoot = $root?->fresh();
            if (! $currentFolder || $currentFolder->path !== $folder->path || ($root && (! $currentRoot || $currentRoot->revision !== $data['revision']))) {
                throw ValidationException::withMessages(['revision' => 'This root changed. Close the editor and reopen it to use the latest settings.']);
            }
            if ($data['action'] === 'delete') {
                $currentRoot->delete();

                return;
            }
            if ($currentFolder->packageRoots()->where('relative_path', $relative)->when($root, fn ($query) => $query->whereKeyNot($root->id))->exists()) {
                throw ValidationException::withMessages(['path' => 'This package root is already selected.']);
            }
            if (! $root && $currentFolder->packageRoots()->count() >= 20) {
                throw ValidationException::withMessages(['path' => 'A linked folder can have up to 20 package roots.']);
            }
            $currentRoot ??= $currentFolder->packageRoots()->make();
            $currentRoot->forceFill([
                'relative_path' => $relative, 'executable_overrides' => array_filter($data['executable_overrides'] ?? []),
                'revision' => $root ? $root->revision + 1 : 1, 'scan_token' => null, 'scan_job_id' => null,
                'scan_state' => 'Not scanned', 'scan_error' => null, 'scan_attempted_at' => null, 'scan_started_at' => null,
                'snapshot' => $relative === $root?->relative_path ? $root?->snapshot : null,
                'scanned_at' => $relative === $root?->relative_path ? $root?->scanned_at : null,
            ])->save();
        });

        return response()->json(['saved' => true]);
    }
}
