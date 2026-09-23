<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ProjectAssetController extends Controller
{
    public function store(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'files' => ['sometimes', 'array', 'list', 'max:100'],
            'files.*' => ['required', 'file', 'max:10240'],
            'paths' => ['sometimes', 'array', 'list', 'max:100'],
            'paths.*' => ['required', 'string', 'max:1024'],
            'directories' => ['sometimes', 'array', 'list', 'max:100'],
            'directories.*' => ['required', 'string', 'max:1024'],
            'folder_id' => ['nullable', 'uuid', Rule::in(array_column($project->asset_folders ?? [], 'id'))],
        ]);
        $uploadedFiles = $data['files'] ?? [];
        $paths = $data['paths'] ?? [];
        $directories = $data['directories'] ?? [];
        if ($uploadedFiles === [] && $directories === []) {
            throw ValidationException::withMessages(['files' => 'Choose files or folders to add.']);
        }
        if ($paths !== [] && count($paths) !== count($uploadedFiles)) {
            throw ValidationException::withMessages(['paths' => 'Each file needs a matching path.']);
        }
        $folderPaths = [];
        foreach ($directories as $directory) {
            $this->addFolderPath($directory, $folderPaths);
        }
        foreach ($paths as $index => $path) {
            $parts = explode('/', $path);
            $name = array_pop($parts);
            if ($name !== $uploadedFiles[$index]->getClientOriginalName()) {
                throw ValidationException::withMessages(['paths' => 'A file path does not match its file name.']);
            }
            if ($parts !== []) {
                $this->addFolderPath(implode('/', $parts), $folderPaths);
            }
        }
        $stored = [];
        try {
            DB::transaction(function () use ($project, $data, $uploadedFiles, $paths, $folderPaths, &$stored): void {
                $this->claim($project, $data['revision']);
                $current = $project->fresh();
                $files = $current->asset_files ?? [];
                if (count($files) + count($uploadedFiles) > 100) {
                    throw ValidationException::withMessages(['files' => 'A project can store up to 100 files.']);
                }
                $folders = $current->asset_folders ?? [];
                $folderIds = [];
                foreach ($folderPaths as $folderPath) {
                    $parts = explode('/', $folderPath);
                    $name = array_pop($parts);
                    $parentId = $parts === [] ? ($data['folder_id'] ?? null) : $folderIds[implode('/', $parts)];
                    $existing = collect($folders)->first(fn (array $folder): bool => ($folder['parent_id'] ?? null) === $parentId && mb_strtolower($folder['name']) === mb_strtolower($name));
                    if ($existing) {
                        $folderIds[$folderPath] = $existing['id'];

                        continue;
                    }
                    if (count($folders) >= 100) {
                        throw ValidationException::withMessages(['directories' => 'A project can store up to 100 asset folders.']);
                    }
                    $folderIds[$folderPath] = (string) Str::uuid7();
                    $folders[] = ['id' => $folderIds[$folderPath], 'name' => $name, 'parent_id' => $parentId];
                }
                foreach ($uploadedFiles as $index => $file) {
                    $path = $file->store('project-assets/'.$project->id, 'local');
                    if (! $path) {
                        throw ValidationException::withMessages(['files' => 'A file could not be stored. Try again.']);
                    }
                    $stored[] = $path;
                    $directory = isset($paths[$index]) ? dirname($paths[$index]) : '.';
                    $files[] = ['id' => (string) Str::uuid7(), 'name' => $file->getClientOriginalName(), 'size' => $file->getSize(), 'path' => $path, 'folder_id' => $directory === '.' ? ($data['folder_id'] ?? null) : $folderIds[$directory]];
                }
                $project->forceFill(['asset_files' => $files, 'asset_folders' => $folders])->save();
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($stored);
            throw $exception;
        }

        return to_route('projects.show', $project)->with('message', 'Assets added');
    }

    /** @param array<int, string> $folderPaths */
    private function addFolderPath(string $path, array &$folderPaths): void
    {
        $parts = explode('/', $path);
        $prefix = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..' || mb_strlen($part) > 100 || ! preg_match('~\A[^/\\\\\x00-\x1F]+\z~u', $part)) {
                throw ValidationException::withMessages(['directories' => 'A folder path contains an invalid name.']);
            }
            $prefix[] = $part;
            $folderPath = implode('/', $prefix);
            if (! in_array($folderPath, $folderPaths, true)) {
                $folderPaths[] = $folderPath;
            }
        }
    }

    public function download(Request $request, Project $project, string $asset): BinaryFileResponse
    {
        $file = collect($project->asset_files ?? [])->firstWhere('id', $asset);
        abort_unless($file && Storage::disk('local')->exists($file['path']), 404);

        $preview = $request->routeIs('projects.assets.preview');
        $mime = $preview ? Project::assetMimeType($file['path']) : 'application/octet-stream';
        abort_if($preview && ! in_array($mime, Project::PREVIEWABLE_ASSET_MIME_TYPES, true), 415, 'A preview is not available for this file.');
        $headers = [
            'Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => $preview ? "default-src 'none'; style-src 'unsafe-inline'; sandbox" : "default-src 'none'; sandbox",
        ];
        $path = Storage::disk('local')->path($file['path']);

        return ($preview ? response()->file($path, [...$headers, 'Content-Disposition' => 'inline']) : response()->download($path, $file['name'], $headers))->setPrivate();
    }

    public function move(Request $request, Project $project, string $asset): RedirectResponse
    {
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'folder_id' => ['present', 'nullable', 'uuid', Rule::in(array_column($project->asset_folders ?? [], 'id'))],
            'name' => ['sometimes', 'required', 'string', 'max:255', 'not_in:.,..', 'regex:~\A[^/\\\\\x00-\x1F]+\z~u'],
        ]);
        DB::transaction(function () use ($project, $asset, $data): void {
            $this->claim($project, $data['revision']);
            $files = collect($project->fresh()->asset_files ?? []);
            abort_unless($files->contains('id', $asset), 404);
            $project->forceFill(['asset_files' => $files->map(fn (array $file): array => $file['id'] === $asset ? [...$file, ...array_intersect_key($data, array_flip(['folder_id', 'name']))] : $file)->all()])->save();
        });

        return to_route('projects.show', $project)->with('message', 'File updated');
    }

    public function saveFolder(Request $request, Project $project, ?string $folder = null): RedirectResponse
    {
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:100', 'not_in:.,..', 'regex:~\A[^/\\\\\x00-\x1F]+\z~u'],
            'parent_id' => ['nullable', 'uuid', Rule::in(array_column($project->asset_folders ?? [], 'id'))],
        ]);
        DB::transaction(function () use ($project, $folder, $data): void {
            $this->claim($project, $data['revision']);
            $folders = collect($project->fresh()->asset_folders ?? []);
            abort_if($folder !== null && ! $folders->contains('id', $folder), 404);
            $targetParentId = array_key_exists('parent_id', $data) ? $data['parent_id'] : ($folders->firstWhere('id', $folder)['parent_id'] ?? null);
            $parentId = $targetParentId;
            if ($folder !== null) {
                $ancestors = [];
                while ($parentId !== null) {
                    if ($parentId === $folder || in_array($parentId, $ancestors, true)) {
                        throw ValidationException::withMessages(['parent_id' => 'A folder cannot contain itself.']);
                    }
                    $ancestors[] = $parentId;
                    $parentId = $folders->firstWhere('id', $parentId)['parent_id'] ?? null;
                }
            }
            if ($folders->contains(fn (array $item): bool => $item['id'] !== $folder && ($item['parent_id'] ?? null) === $targetParentId && mb_strtolower($item['name']) === mb_strtolower($data['name']))) {
                throw ValidationException::withMessages(['name' => 'A folder with this name already exists.']);
            }
            if ($folder === null && $folders->count() >= 100) {
                throw ValidationException::withMessages(['name' => 'A project can store up to 100 asset folders.']);
            }
            $entry = ['id' => $folder ?? (string) Str::uuid7(), 'name' => $data['name'], 'parent_id' => $targetParentId];
            $folders = $folder === null ? $folders->push($entry) : $folders->map(fn (array $item): array => $item['id'] === $folder ? $entry : $item);
            $project->forceFill(['asset_folders' => $folders->values()->all()])->save();
        });

        return to_route('projects.show', $project)->with('message', $folder === null ? 'Folder created' : 'Folder renamed');
    }

    public function destroyFolder(Request $request, Project $project, string $folder): RedirectResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($project, $folder, $data): void {
            $this->claim($project, $data['revision']);
            $current = $project->fresh();
            $folders = collect($current->asset_folders ?? []);
            abort_unless($folders->contains('id', $folder), 404);
            $parentId = $folders->firstWhere('id', $folder)['parent_id'] ?? null;
            foreach ($folders->where('parent_id', $folder) as $child) {
                if ($folders->contains(fn (array $item): bool => $item['id'] !== $folder && ($item['parent_id'] ?? null) === $parentId && mb_strtolower($item['name']) === mb_strtolower($child['name']))) {
                    throw ValidationException::withMessages(['folder' => 'Rename or move the conflicting subfolder before removing this folder.']);
                }
            }
            $project->forceFill([
                'asset_folders' => $folders->where('id', '!=', $folder)->map(fn (array $item): array => ($item['parent_id'] ?? null) === $folder ? [...$item, 'parent_id' => $parentId] : $item)->values()->all(),
                'asset_files' => array_map(fn (array $file): array => ($file['folder_id'] ?? null) === $folder ? [...$file, 'folder_id' => $parentId] : $file, $current->asset_files ?? []),
            ])->save();
        });

        return to_route('projects.show', $project)->with('message', 'Folder removed. Its contents moved to the parent folder.');
    }

    public function destroy(Request $request, Project $project, string $asset): RedirectResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        $path = DB::transaction(function () use ($project, $asset, $data): string {
            $this->claim($project, $data['revision']);
            $files = collect($project->fresh()->asset_files ?? []);
            $file = $files->firstWhere('id', $asset);
            abort_unless($file, 404);
            $project->forceFill(['asset_files' => $files->where('id', '!=', $asset)->values()->all()])->save();

            return $file['path'];
        });
        Storage::disk('local')->delete($path);

        return to_route('projects.show', $project)->with('message', 'File removed');
    }

    private function claim(Project $project, int $revision): void
    {
        if (! Project::whereKey($project->id)->where('revision', $revision)->update(['revision' => DB::raw('revision + 1')])) {
            throw ValidationException::withMessages(['revision' => 'This project changed. Reload it before changing files.']);
        }
    }
}
