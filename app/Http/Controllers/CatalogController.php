<?php

namespace App\Http\Controllers;

use App\Actions\InspectFolder;
use App\Models\Project;
use App\Rules\ProjectUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Native\Desktop\Dialog;
use Native\Desktop\Facades\Shell;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class CatalogController extends Controller
{
    public function icon(Project $project): BinaryFileResponse
    {
        abort_unless($project->icon_path && Storage::disk('local')->exists($project->icon_path), 404);

        return response()->file(Storage::disk('local')->path($project->icon_path), [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function inspect(Request $request, InspectFolder $inspect, Dialog $dialog): JsonResponse
    {
        $data = $request->validate(['path' => ['nullable', 'string', 'max:4096']]);
        $path = $data['path'] ?? null;
        if (! $path) {
            if (! config('nativephp-internal.running')) {
                throw ValidationException::withMessages(['path' => 'Enter the absolute folder path.']);
            }
            try {
                $path = $dialog->folders()->title('Select a project folder')->button('Choose folder')->asSheet()->open();
            } catch (Throwable) {
                throw ValidationException::withMessages(['path' => 'The folder picker could not open. Try again.']);
            }
        }

        return response()->json(['folder' => $path ? $inspect->handle($path) : null]);
    }

    public function open(Project $project, string $kind, string $id): JsonResponse
    {
        $record = $project->{$kind}()->findOrFail($id);
        if (! config('nativephp-internal.running')) {
            throw ValidationException::withMessages(['target' => 'Open folders from the desktop app.']);
        }
        if ($kind === 'folders') {
            if (! is_dir($record->path) || ! is_readable($record->path)) {
                throw ValidationException::withMessages(['target' => 'This folder is unavailable. Relink it to continue.']);
            }
            $error = Shell::openFile($record->path);
            if ($error !== '') {
                throw ValidationException::withMessages(['target' => 'The folder could not be opened.']);
            }
        } else {
            $url = match ($kind) {
                'repositories' => $record->web_url,
                'secrets' => $record->management_url,
                default => $record->url,
            };
            Validator::make(['target' => $url], ['target' => [new ProjectUrl]])->validate();
            Shell::openExternal($url);
        }

        return response()->json(['opened' => true]);
    }
}
