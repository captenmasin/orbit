<?php

namespace App\Http\Controllers;

use App\Actions\ProtectCredential;
use App\Actions\RecordAccessEvent;
use App\EnvFile;
use App\Models\Project;
use App\Models\ProjectSecret;
use App\Rules\ProjectUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Native\Desktop\Dialog;
use Native\Desktop\Facades\Clipboard;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class SecretController extends Controller
{
    public function store(Request $request, Project $project, ProtectCredential $crypto, RecordAccessEvent $events): JsonResponse
    {
        $value = $this->takeValue($request);
        $data = $this->metadata($request, $project);
        try {
            $ciphertext = $crypto->encrypt($value);

            return DB::transaction(function () use ($project, $data, $ciphertext, $events): JsonResponse {
                $this->advanceProjectRevision($project, $data['project_revision']);
                $secret = $project->secrets()->create([
                    'environment' => $data['environment'],
                    'name' => $data['name'],
                    'ciphertext' => $ciphertext,
                    'service' => $data['service'],
                    'description' => $data['description'],
                    'management_url' => $data['management_url'],
                ]);
                $events->handle('create', 'Succeeded', secretId: $secret->id);

                return response()->json(['saved' => true]);
            });
        } catch (ConflictHttpException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'errors' => ['secret' => $exception->getMessage()]], 422);
        } finally {
            unset($value, $ciphertext);
        }
    }

    public function paste(Request $request, Project $project, ProtectCredential $crypto, RecordAccessEvent $events, EnvFile $envFile): JsonResponse
    {
        $entries = $this->takeEntries($request, $envFile);
        $data = $request->validate([
            'environment' => ['required', 'string', 'max:100', 'regex:/\S/u'],
            'service' => ['nullable', 'string', 'max:100'],
            'project_revision' => ['required', 'integer', 'min:1'],
        ]);
        $environment = trim($data['environment']);
        $service = isset($data['service']) ? (trim($data['service']) ?: null) : null;
        try {
            $names = array_keys($entries);
            $ciphertexts = [];
            foreach ($entries as $name => $value) {
                $ciphertexts[$name] = $crypto->encrypt($value);
            }
            unset($entries);

            return DB::transaction(function () use ($project, $names, $ciphertexts, $environment, $service, $data, $events): JsonResponse {
                if ($project->secrets()->where('environment', $environment)->whereIn('name', $names)->exists()) {
                    throw ValidationException::withMessages(['entries' => 'Some secret names already exist in this environment.']);
                }
                $this->advanceProjectRevision($project, (int) $data['project_revision']);
                foreach ($names as $name) {
                    $secret = $project->secrets()->create(['environment' => $environment, 'name' => $name, 'ciphertext' => $ciphertexts[$name], 'service' => $service]);
                    $events->handle('create', 'Succeeded', secretId: $secret->id);
                }

                return response()->json(['saved' => true]);
            });
        } catch (ConflictHttpException|ValidationException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'errors' => ['secret' => $exception->getMessage()]], 422);
        } finally {
            unset($entries, $ciphertexts, $names);
        }
    }

    public function previewImport(Request $request, Project $project, Dialog $dialog, EnvFile $envFile): JsonResponse
    {
        $environment = $this->environment($request);
        if (! config('nativephp-internal.running')) {
            throw ValidationException::withMessages(['entries' => 'Import .env files from the desktop app.']);
        }
        try {
            $path = $dialog->files()->withHiddenFiles()->title('Select a .env file')->button('Preview import')->asSheet()->open();
        } catch (Throwable) {
            throw ValidationException::withMessages(['entries' => 'The file picker could not open. Try again.']);
        }
        if (! $path) {
            return response()->json(['preview' => null]);
        }
        [$entries, $hash] = $this->readEnvFile($path, $envFile);
        $collisions = $project->secrets()->where('environment', $environment)->whereIn('name', array_keys($entries))->pluck('name')->flip();
        $request->session()->put('secret-import:'.$project->id, ['path' => $path, 'hash' => $hash, 'environment' => $environment]);

        return response()->json(['preview' => [
            'source' => basename($path),
            'entries' => array_map(fn (string $name): array => ['name' => $name, 'collision' => isset($collisions[$name])], array_keys($entries)),
        ]]);
    }

    public function import(Request $request, Project $project, ProtectCredential $crypto, RecordAccessEvent $events, EnvFile $envFile): JsonResponse
    {
        $data = $request->validate([
            'environment' => ['required', 'string', 'max:100', 'regex:/\S/u'],
            'project_revision' => ['required', 'integer', 'min:1'],
        ]);
        $preview = $request->session()->pull('secret-import:'.$project->id);
        $environment = trim($data['environment']);
        if (! is_array($preview) || ($preview['environment'] ?? null) !== $environment) {
            throw ValidationException::withMessages(['entries' => 'Preview the file again before importing.']);
        }
        [$entries, $hash] = $this->readEnvFile($preview['path'] ?? '', $envFile);
        if (! isset($preview['hash']) || ! hash_equals($preview['hash'], $hash)) {
            throw ValidationException::withMessages(['entries' => 'The file changed. Preview it again before importing.']);
        }
        $existing = $project->secrets()->where('environment', $environment)->whereIn('name', array_keys($entries))->pluck('name')->all();
        $entries = array_diff_key($entries, array_flip($existing));
        if (! $entries) {
            throw ValidationException::withMessages(['entries' => 'All entries already exist in this environment.']);
        }
        try {
            $names = array_keys($entries);
            $ciphertexts = [];
            foreach ($entries as $name => $value) {
                $ciphertexts[$name] = $crypto->encrypt($value);
            }
            unset($entries);

            return DB::transaction(function () use ($project, $names, $ciphertexts, $environment, $data, $events, $existing): JsonResponse {
                if ($project->secrets()->where('environment', $environment)->whereIn('name', $names)->exists()) {
                    throw ValidationException::withMessages(['entries' => 'Some secrets changed. Preview the file again before importing.']);
                }
                $this->advanceProjectRevision($project, (int) $data['project_revision']);
                foreach ($names as $name) {
                    $secret = $project->secrets()->create(['environment' => $environment, 'name' => $name, 'ciphertext' => $ciphertexts[$name]]);
                    $events->handle('import', 'Succeeded', secretId: $secret->id);
                }

                return response()->json(['saved' => true, 'imported' => count($names), 'skipped' => count($existing)]);
            });
        } catch (ConflictHttpException|ValidationException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'errors' => ['secret' => $exception->getMessage()]], 422);
        } finally {
            unset($entries, $ciphertexts, $names);
        }
    }

    public function previewExport(Request $request, Project $project, Dialog $dialog): JsonResponse
    {
        [$environment, $names] = $this->exportSelection($request, $project);
        if (! config('nativephp-internal.running')) {
            throw ValidationException::withMessages(['names' => 'Export .env files from the desktop app.']);
        }
        try {
            $path = $dialog->title('Export .env file')->filter('Environment files', ['env'])->button('Preview export')->asSheet()->save();
        } catch (Throwable) {
            throw ValidationException::withMessages(['names' => 'The save dialog could not open. Try again.']);
        }
        if (! $path) {
            return response()->json(['preview' => null]);
        }
        $state = $this->destinationState($path);
        $request->session()->put('secret-export:'.$project->id, ['path' => $path, 'state' => $state, 'environment' => $environment, 'names' => $names]);

        return response()->json(['preview' => ['destination' => basename($path), 'exists' => $state['exists']]]);
    }

    public function export(Request $request, Project $project, ProtectCredential $crypto, RecordAccessEvent $events, EnvFile $envFile): JsonResponse
    {
        [$environment, $names] = $this->exportSelection($request, $project);
        $data = $request->validate(['project_revision' => ['required', 'integer', 'min:1'], 'overwrite' => ['required', 'boolean']]);
        $preview = $request->session()->pull('secret-export:'.$project->id);
        if (! is_array($preview) || ($preview['environment'] ?? null) !== $environment || ($preview['names'] ?? null) !== $names) {
            throw ValidationException::withMessages(['names' => 'Choose a destination again before exporting.']);
        }
        $path = $preview['path'] ?? null;
        if (! is_string($path) || ! isset($preview['state']) || $this->destinationState($path) !== $preview['state']) {
            throw ValidationException::withMessages(['names' => 'The destination changed. Choose it again before exporting.']);
        }
        if ($preview['state']['exists'] && ! $data['overwrite']) {
            throw ValidationException::withMessages(['overwrite' => 'Confirm replacement of the existing file.']);
        }
        if ($project->revision !== (int) $data['project_revision']) {
            throw new ConflictHttpException('This project changed. Reload before exporting its secrets.');
        }
        $secrets = $project->secrets()->where('environment', $environment)->whereIn('name', $names)->get()->keyBy('name');
        try {
            $entries = [];
            foreach ($names as $name) {
                $entries[$name] = $crypto->decrypt($secrets[$name]->ciphertext);
            }
            $this->writeEnvFile($path, $envFile->serialize($entries));
            foreach ($secrets as $secret) {
                $events->handle('export', 'Succeeded', secretId: $secret->id);
            }

            return response()->json(['exported' => true]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'errors' => ['secret' => $exception->getMessage()]], 422);
        } finally {
            unset($entries, $secrets);
        }
    }

    public function replace(Request $request, Project $project, string $secret, ProtectCredential $crypto, RecordAccessEvent $events): JsonResponse
    {
        $value = $this->takeValue($request);
        $data = $this->revision($request);
        $current = $project->secrets()->findOrFail($secret);
        if ($project->revision !== $data['project_revision'] || $current->revision !== $data['revision']) {
            throw new ConflictHttpException('This secret changed. Reload before replacing it.');
        }
        try {
            $ciphertext = $crypto->encrypt($value);

            return DB::transaction(function () use ($project, $current, $data, $ciphertext, $events): JsonResponse {
                $this->advanceProjectRevision($project, $data['project_revision']);
                if (! ProjectSecret::whereKey($current->id)->where('project_id', $project->id)->where('revision', $data['revision'])->update([
                    'ciphertext' => $ciphertext,
                    'revision' => $current->revision + 1,
                    'updated_at' => now(),
                ])) {
                    throw new ConflictHttpException('This secret changed. Reload before replacing it.');
                }
                $events->handle('replace', 'Succeeded', secretId: $current->id);

                return response()->json(['saved' => true]);
            });
        } catch (ConflictHttpException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'errors' => ['secret' => $exception->getMessage()]], 422);
        } finally {
            unset($value, $ciphertext);
        }
    }

    public function updateMetadata(Request $request, Project $project, string $secret): JsonResponse
    {
        $data = $this->revision($request);
        $context = $this->context($request);
        $current = $project->secrets()->select(['id', 'project_id', 'revision'])->findOrFail($secret);

        return DB::transaction(function () use ($project, $current, $data, $context): JsonResponse {
            $this->advanceProjectRevision($project, $data['project_revision']);
            if (! ProjectSecret::whereKey($current->id)->where('project_id', $project->id)->where('revision', $data['revision'])->update([
                ...$context,
                'revision' => $current->revision + 1,
                'updated_at' => now(),
            ])) {
                throw new ConflictHttpException('This secret changed. Reload before editing its context.');
            }

            return response()->json(['saved' => true]);
        });
    }

    public function bulkMetadata(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'project_revision' => ['required', 'integer', 'min:1'],
            'secrets' => ['required', 'array', 'min:1', 'max:1000'],
            'secrets.*.id' => ['required', 'uuid', 'distinct'],
            'secrets.*.revision' => ['required', 'integer', 'min:1'],
            'environment' => ['sometimes', 'required', 'string', 'max:100', 'regex:/\S/u'],
            'service' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);
        if (! array_key_exists('environment', $data) && ! array_key_exists('service', $data)) {
            throw ValidationException::withMessages(['secrets' => 'Choose an environment or category to update.']);
        }
        $changes = [];
        if (array_key_exists('environment', $data)) {
            $changes['environment'] = trim($data['environment']);
        }
        if (array_key_exists('service', $data)) {
            $changes['service'] = isset($data['service']) ? (trim($data['service']) ?: null) : null;
        }

        return DB::transaction(function () use ($project, $data, $changes): JsonResponse {
            $selected = collect($data['secrets'])->keyBy('id');
            $secrets = $project->secrets()->whereIn('id', $selected->keys())->get();
            if ($secrets->count() !== $selected->count() || $secrets->contains(fn (ProjectSecret $secret): bool => $secret->revision !== (int) $selected[$secret->id]['revision'])) {
                throw new ConflictHttpException('Some secrets changed. Reload before updating them.');
            }
            if (isset($changes['environment'])) {
                if ($secrets->pluck('name')->unique()->count() !== $secrets->count() || $project->secrets()->where('environment', $changes['environment'])->whereIn('name', $secrets->pluck('name'))->whereNotIn('id', $selected->keys())->exists()) {
                    throw ValidationException::withMessages(['environment' => 'The selected secrets would have duplicate names in that environment.']);
                }
            }
            $this->advanceProjectRevision($project, (int) $data['project_revision']);
            foreach ($secrets as $secret) {
                if (! ProjectSecret::whereKey($secret->id)->where('revision', $secret->revision)->update([...$changes, 'revision' => $secret->revision + 1, 'updated_at' => now()])) {
                    throw new ConflictHttpException('Some secrets changed. Reload before updating them.');
                }
            }

            return response()->json(['saved' => true, 'updated' => $secrets->count()]);
        });
    }

    public function destroy(Request $request, Project $project, string $secret, RecordAccessEvent $events): JsonResponse
    {
        $data = $this->revision($request);
        $current = $project->secrets()->findOrFail($secret);

        return DB::transaction(function () use ($project, $current, $data, $events): JsonResponse {
            $this->advanceProjectRevision($project, $data['project_revision']);
            if (! ProjectSecret::whereKey($current->id)->where('project_id', $project->id)->where('revision', $data['revision'])->delete()) {
                throw new ConflictHttpException('This secret changed. Reload before removing it.');
            }
            $events->handle('remove', 'Succeeded', secretId: $current->id);

            return response()->json(['removed' => true]);
        });
    }

    public function reveal(Request $request, Project $project, string $secret, ProtectCredential $crypto, RecordAccessEvent $events): JsonResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        $current = $project->secrets()->findOrFail($secret);
        if ($current->revision !== (int) $data['revision']) {
            throw new ConflictHttpException('This secret changed. Reload before revealing it.');
        }
        try {
            $value = $crypto->decrypt($current->ciphertext);
            $events->handle('reveal', 'Succeeded', secretId: $current->id);

            return response()->json(['value' => $value])->header('Cache-Control', 'private, no-store');
        } catch (RuntimeException $exception) {
            $events->handle('reveal', 'Failed', secretId: $current->id);

            return response()->json(['message' => $exception->getMessage(), 'errors' => ['secret' => $exception->getMessage()]], 422);
        } finally {
            unset($value);
        }
    }

    public function copy(Request $request, Project $project, string $secret, ProtectCredential $crypto, RecordAccessEvent $events): JsonResponse
    {
        $current = $this->current($request, $project, $secret);
        try {
            $value = $crypto->decrypt($current->ciphertext);
            Clipboard::text($value);
            if (! hash_equals($value, Clipboard::text())) {
                throw new RuntimeException;
            }
            $events->handle('copy', 'Succeeded', secretId: $current->id);

            return response()->json(['copied' => true]);
        } catch (Throwable) {
            $events->handle('copy', 'Failed', secretId: $current->id);

            return response()->json(['message' => 'The clipboard could not be updated.', 'errors' => ['secret' => 'The clipboard could not be updated.']], 422);
        } finally {
            unset($value);
        }
    }

    public function clearClipboard(Request $request, Project $project, string $secret, ProtectCredential $crypto): JsonResponse
    {
        $current = $this->current($request, $project, $secret);
        try {
            $value = $crypto->decrypt($current->ciphertext);
            if (hash_equals($value, Clipboard::text())) {
                Clipboard::clear();
            }

            return response()->json(['cleared' => true]);
        } catch (Throwable) {
            return response()->json(['message' => 'The clipboard could not be cleared.'], 422);
        } finally {
            unset($value);
        }
    }

    /**
     * @return array{environment: string, name: string, project_revision: int, service: ?string, description: ?string, management_url: ?string}
     */
    private function metadata(Request $request, Project $project): array
    {
        $data = $request->validate([
            'environment' => ['required', 'string', 'max:100', 'regex:/\S/u'],
            'name' => ['required', 'string', 'max:255', 'regex:/\A[A-Za-z_][A-Za-z0-9_]*\z/D'],
            'project_revision' => ['required', 'integer', 'min:1'],
        ]);
        $data['environment'] = trim($data['environment']);
        if ($project->secrets()->where('environment', $data['environment'])->where('name', $data['name'])->exists()) {
            throw ValidationException::withMessages(['name' => 'A secret with this name already exists in this environment.']);
        }

        return [...$data, ...$this->context($request), 'project_revision' => (int) $data['project_revision']];
    }

    /** @return array{service: ?string, description: ?string, management_url: ?string} */
    private function context(Request $request): array
    {
        $data = $request->validate([
            'service' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'management_url' => ['nullable', 'string', 'max:2048', new ProjectUrl],
        ]);

        return [
            'service' => isset($data['service']) ? (trim($data['service']) ?: null) : null,
            'description' => $data['description'] ?? null,
            'management_url' => $data['management_url'] ?? null,
        ];
    }

    /**
     * @return array{project_revision: int, revision: int}
     */
    private function revision(Request $request): array
    {
        $data = $request->validate([
            'project_revision' => ['required', 'integer', 'min:1'],
            'revision' => ['required', 'integer', 'min:1'],
        ]);

        return ['project_revision' => (int) $data['project_revision'], 'revision' => (int) $data['revision']];
    }

    private function current(Request $request, Project $project, string $secret): ProjectSecret
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        $current = $project->secrets()->findOrFail($secret);
        if ($current->revision !== (int) $data['revision']) {
            throw new ConflictHttpException('This secret changed. Reload before using it.');
        }

        return $current;
    }

    private function takeValue(Request $request): string
    {
        if (! $request->has('value')) {
            throw ValidationException::withMessages(['value' => 'Enter a value.']);
        }
        $value = $request->input('value');
        $request->request->remove('value');
        $request->json()->remove('value');
        Validator::make(['value' => $value], ['value' => [
            'string',
            function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || strlen($value) > 1024 * 1024) {
                    $fail('The value must be valid UTF-8 text no larger than 1 MB.');
                }
            },
        ]])->validate();

        return $value;
    }

    /**
     * @return array<string, string>
     */
    private function takeEntries(Request $request, EnvFile $envFile): array
    {
        if (! $request->has('entries')) {
            throw ValidationException::withMessages(['entries' => 'Paste at least one NAME=value line.']);
        }
        $entries = $request->input('entries');
        $request->request->remove('entries');
        $request->json()->remove('entries');
        if (! is_string($entries) || ! mb_check_encoding($entries, 'UTF-8') || str_contains($entries, "\0") || strlen($entries) > 1024 * 1024) {
            throw ValidationException::withMessages(['entries' => 'Paste valid UTF-8 text no larger than 1 MB.']);
        }
        try {
            $parsed = $envFile->parse($entries);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['entries' => $exception->getMessage()]);
        }
        if (! $parsed) {
            throw ValidationException::withMessages(['entries' => 'Paste at least one NAME=value line.']);
        }
        if (count($parsed) > 1000) {
            throw ValidationException::withMessages(['entries' => 'Paste no more than 1,000 entries at once.']);
        }

        return $parsed;
    }

    private function environment(Request $request): string
    {
        $data = $request->validate(['environment' => ['required', 'string', 'max:100', 'regex:/\S/u']]);

        return trim($data['environment']);
    }

    /**
     * @return array{string, list<string>}
     */
    private function exportSelection(Request $request, Project $project): array
    {
        $data = $request->validate([
            'environment' => ['required', 'string', 'max:100', 'regex:/\S/u'],
            'names' => ['required', 'array', 'min:1', 'max:1000'],
            'names.*' => ['required', 'string', 'max:255', 'distinct', 'regex:/\A[A-Za-z_][A-Za-z0-9_]*\z/D'],
        ]);
        $environment = trim($data['environment']);
        $names = array_values($data['names']);
        if ($project->secrets()->where('environment', $environment)->whereIn('name', $names)->count() !== count($names)) {
            throw ValidationException::withMessages(['names' => 'Choose secrets from the selected environment.']);
        }

        return [$environment, $names];
    }

    /**
     * @return array{exists: bool, hash: string|null}
     */
    private function destinationState(string $path): array
    {
        if (! file_exists($path)) {
            return ['exists' => false, 'hash' => null];
        }
        if (! is_file($path) || ! is_readable($path) || ! ($hash = hash_file('sha256', $path))) {
            throw ValidationException::withMessages(['names' => 'Choose a writable file destination.']);
        }

        return ['exists' => true, 'hash' => $hash];
    }

    private function writeEnvFile(string $path, string $content): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) || ! is_writable($directory) || ($temporary = tempnam($directory, '.orbit-env-')) === false) {
            throw new RuntimeException('The .env file could not be written.');
        }
        try {
            $file = fopen($temporary, 'wb');
            if (! chmod($temporary, 0600) || ! $file || fwrite($file, $content) !== strlen($content) || ! fflush($file) || ! fclose($file) || ! rename($temporary, $path)) {
                if (is_resource($file)) {
                    fclose($file);
                }
                throw new RuntimeException('The .env file could not be written.');
            }
        } finally {
            unset($content);
            if (isset($temporary) && file_exists($temporary)) {
                unlink($temporary);
            }
        }
    }

    /**
     * @return array{array<string, string>, string}
     */
    private function readEnvFile(string $path, EnvFile $envFile): array
    {
        if (! is_file($path) || ! is_readable($path) || ($size = filesize($path)) === false || $size > 1024 * 1024) {
            throw ValidationException::withMessages(['entries' => 'Choose a readable .env file no larger than 1 MB.']);
        }
        $content = file_get_contents($path);
        if (! is_string($content)) {
            throw ValidationException::withMessages(['entries' => 'The .env file could not be read.']);
        }
        try {
            $entries = $envFile->parse($content);
            $hash = hash('sha256', $content);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['entries' => $exception->getMessage()]);
        } finally {
            unset($content);
        }

        return [$entries, $hash];
    }

    private function advanceProjectRevision(Project $project, int $revision): void
    {
        if (! Project::whereKey($project->id)->where('revision', $revision)->update(['revision' => $revision + 1])) {
            throw new ConflictHttpException('This project changed. Reload before changing its secrets.');
        }
    }
}
