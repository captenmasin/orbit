<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ProjectAssetController extends Controller
{
    public function store(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'files' => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => ['required', 'file', 'max:10240'],
        ]);
        $stored = [];
        try {
            DB::transaction(function () use ($project, $data, &$stored): void {
                $this->claim($project, $data['revision']);
                $files = $project->fresh()->asset_files ?? [];
                if (count($files) + count($data['files']) > 100) {
                    throw ValidationException::withMessages(['files' => 'A project can store up to 100 files.']);
                }
                foreach ($data['files'] as $file) {
                    $path = $file->store('project-assets/'.$project->id, 'local');
                    if (! $path) {
                        throw ValidationException::withMessages(['files' => 'A file could not be stored. Try again.']);
                    }
                    $stored[] = $path;
                    $files[] = ['id' => (string) Str::uuid7(), 'name' => $file->getClientOriginalName(), 'size' => $file->getSize(), 'path' => $path];
                }
                $project->forceFill(['asset_files' => $files])->save();
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($stored);
            throw $exception;
        }

        return to_route('projects.show', $project)->with('message', 'Files uploaded');
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
