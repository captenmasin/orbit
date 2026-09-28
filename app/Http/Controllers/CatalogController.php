<?php

namespace App\Http\Controllers;

use App\Actions\CloneRepository;
use App\Actions\InspectFolder;
use App\Actions\ProtectCredential;
use App\Actions\ProviderHttp;
use App\Actions\ReadProvider;
use App\Models\Project;
use App\Models\ProviderConnection;
use App\Rules\ProjectUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Native\Desktop\Dialog;
use Native\Desktop\Facades\Shell;
use RuntimeException;
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
                $path = $dialog->properties(['openDirectory', 'createDirectory'])->title('Select a project folder')->button('Choose folder')->asSheet()->open();
            } catch (Throwable) {
                throw ValidationException::withMessages(['path' => 'The folder picker could not open. Try again.']);
            }
        }

        return response()->json(['folder' => $path ? $inspect->handle($path) : null]);
    }

    public function clone(Request $request, Dialog $dialog, ProviderHttp $http, ReadProvider $reader, ProtectCredential $crypto, CloneRepository $clone): JsonResponse
    {
        if (! config('nativephp-internal.running')) {
            throw ValidationException::withMessages(['connection' => 'Clone repositories from the desktop app.']);
        }
        $data = $request->validate(['connection_id' => ['required', 'uuid'], 'full_name' => ['required', 'string', 'max:255']]);
        $connection = ProviderConnection::find($data['connection_id']);
        if (! $connection || ! in_array($connection->provider, ['github', 'gitlab'], true)) {
            throw ValidationException::withMessages(['connection' => 'Choose a saved Git connection.']);
        }
        try {
            $metadata = $http->using($connection, fn (string $credential): array => $reader->repository($connection->provider, $data['full_name'], $credential));
            $url = 'https://'.($connection->provider === 'github' ? 'github.com' : 'gitlab.com').'/'.$metadata['provider_name'];
            if (strcasecmp($metadata['provider_name'], $data['full_name']) !== 0 || strcasecmp($metadata['provider_url'], $url) !== 0) {
                throw new RuntimeException('Provider returned a different repository. Choose it again.');
            }
        } catch (Throwable $exception) {
            $message = get_class($exception) === RuntimeException::class ? $exception->getMessage() : 'Repository could not be verified.';
            throw ValidationException::withMessages(['repository' => $message]);
        }

        try {
            $parent = $dialog->properties(['openDirectory', 'createDirectory'])->title('Choose where to clone the repository')->button('Choose folder')->asSheet()->open();
        } catch (Throwable) {
            throw ValidationException::withMessages(['folder' => 'The folder picker could not open. Try again.']);
        }
        if ($parent === null) {
            return response()->json(['folder' => null]);
        }
        if (! is_string($parent)) {
            throw ValidationException::withMessages(['folder' => 'Choose a folder you can write to.']);
        }
        $current = $connection->fresh();
        if (! $current || $current->revision !== $connection->revision || $current->state === 'Token required' || $current->retry_at?->isFuture()) {
            throw ValidationException::withMessages(['connection' => 'Connection changed. Choose the repository again.']);
        }
        try {
            $credential = $crypto->decrypt($current->encrypted_token);

            return response()->json(['folder' => $clone->handle($url.'.git', $parent, $connection->provider, $credential)]);
        } catch (RuntimeException $exception) {
            $message = get_class($exception) === RuntimeException::class ? $exception->getMessage() : 'Repository could not be cloned.';
            throw ValidationException::withMessages(['connection' => $message]);
        } finally {
            unset($credential);
        }
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
