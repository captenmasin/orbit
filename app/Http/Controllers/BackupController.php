<?php

namespace App\Http\Controllers;

use App\Actions\ProtectCredential;
use App\OrbitBackup;
use App\WorkspaceBackup;
use App\WorkspaceRestore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Native\Desktop\Dialog;
use RuntimeException;
use Throwable;

class BackupController extends Controller
{
    public function previewExport(Request $request, Dialog $dialog): JsonResponse
    {
        if (! config('nativephp-internal.running')) {
            throw ValidationException::withMessages(['backup' => 'Export backups from the desktop app.']);
        }
        try {
            $path = $dialog->title('Export Orbit backup')->filter('Orbit backups', ['orbitbackup'])->button('Choose backup destination')->asSheet()->save();
        } catch (Throwable) {
            throw ValidationException::withMessages(['backup' => 'The save dialog could not open. Try again.']);
        }
        if (! $path) {
            return response()->json(['preview' => null]);
        }
        $state = $this->destinationState($path);
        $request->session()->put('backup-export', ['path' => $path, 'state' => $state]);

        return response()->json(['preview' => ['destination' => basename($path), 'exists' => $state['exists']]]);
    }

    public function export(Request $request, OrbitBackup $backup, WorkspaceBackup $workspace, ProtectCredential $crypto): JsonResponse
    {
        $password = $this->takePassword($request);
        $data = $request->validate([
            'include_secrets' => ['required', 'boolean'],
            'overwrite' => ['required', 'boolean'],
        ]);
        if ($data['include_secrets']) {
            abort_unless((int) $request->session()->get('secret_pin_unlocked_until', 0) > now()->timestamp, 423, 'Unlock secrets with your PIN.');
        }
        $preview = $request->session()->pull('backup-export');
        if (! is_array($preview) || ! is_string($preview['path'] ?? null) || ! is_array($preview['state'] ?? null) || $this->destinationState($preview['path']) !== $preview['state']) {
            throw ValidationException::withMessages(['backup' => 'Choose the backup destination again before exporting.']);
        }
        if ($preview['state']['exists'] && ! $data['overwrite']) {
            throw ValidationException::withMessages(['overwrite' => 'Confirm replacement of the existing backup.']);
        }
        try {
            $contents = $backup->write($workspace->records((bool) $data['include_secrets'], $crypto), $password);
            $this->writeBackup($preview['path'], $contents);

            return response()->json(['exported' => true]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'errors' => ['backup' => $exception->getMessage()]], 422);
        } finally {
            unset($password, $contents);
        }
    }

    public function previewRestore(Request $request, Dialog $dialog, OrbitBackup $backup, WorkspaceRestore $restore, ProtectCredential $crypto): JsonResponse
    {
        $password = $this->takePassword($request, false);
        if (! config('nativephp-internal.running')) {
            throw ValidationException::withMessages(['backup' => 'Restore backups from the desktop app.']);
        }
        try {
            $path = $dialog->files()->filter('Orbit backups', ['orbitbackup'])->title('Select Orbit backup')->button('Preview restore')->asSheet()->open();
        } catch (Throwable) {
            throw ValidationException::withMessages(['backup' => 'The file picker could not open. Try again.']);
        }
        if (! $path) {
            return response()->json(['preview' => null]);
        }
        try {
            if (! is_file($path) || ! is_readable($path) || ($size = filesize($path)) === false || $size > 256 * 1024 * 1024 || ($contents = file_get_contents($path)) === false) {
                throw new RuntimeException;
            }
            $staged = $restore->stage($backup->read($contents, $password), $crypto);
            $request->session()->put('backup-restore', [
                'path' => $path,
                'hash' => hash('sha256', $contents),
                'workspace' => $this->workspaceFingerprint(),
            ]);

            return response()->json(['preview' => $staged['summary']]);
        } catch (Throwable) {
            return response()->json(['message' => 'The backup password is incorrect or the file is damaged.', 'errors' => ['backup' => 'The backup password is incorrect or the file is damaged.']], 422);
        } finally {
            unset($password, $contents, $staged);
        }
    }

    public function applyRestore(Request $request, OrbitBackup $backup, WorkspaceRestore $restore, ProtectCredential $crypto): JsonResponse
    {
        $password = $this->takePassword($request, false);
        $request->validate(['confirm' => ['accepted']]);
        $preview = $request->session()->pull('backup-restore');
        if (! is_array($preview) || ! is_string($preview['path'] ?? null) || ! is_string($preview['hash'] ?? null) || ! is_string($preview['workspace'] ?? null) || $preview['workspace'] !== $this->workspaceFingerprint()) {
            throw ValidationException::withMessages(['backup' => 'The workspace changed. Preview the backup again before restoring.']);
        }
        try {
            if (! is_file($preview['path']) || ! is_readable($preview['path']) || ($contents = file_get_contents($preview['path'])) === false || ! hash_equals($preview['hash'], hash('sha256', $contents))) {
                throw new RuntimeException;
            }
            $state = $this->snapshot($preview['hash']);
            $restore->apply($restore->stage($backup->read($contents, $password), $crypto));
            $request->session()->invalidate();
            DB::table('restore_states')->where('id', $state)->update(['phase' => 'Applied', 'updated_at' => now()]);

            return response()->json(['restored' => true]);
        } catch (Throwable) {
            return response()->json(['message' => 'The backup could not be restored.', 'errors' => ['backup' => 'The backup could not be restored.']], 422);
        } finally {
            unset($password, $contents);
        }
    }

    private function takePassword(Request $request, bool $confirmed = true): string
    {
        $password = $request->input('password');
        $confirmation = $request->input('password_confirmation');
        $request->request->remove('password');
        $request->request->remove('password_confirmation');
        $request->json()->remove('password');
        $request->json()->remove('password_confirmation');
        $rules = [
            'password' => ['required', 'string', 'min:12', 'max:4096'],
        ];
        if ($confirmed) {
            $rules['password_confirmation'] = ['required', 'string'];
        }
        $validated = Validator::make(['password' => $password, 'password_confirmation' => $confirmation], $rules)->validate();
        if ($confirmed && ! hash_equals($validated['password'], $validated['password_confirmation'])) {
            throw ValidationException::withMessages(['password_confirmation' => 'The passwords do not match.']);
        }

        return $validated['password'];
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
            throw ValidationException::withMessages(['backup' => 'Choose a writable backup destination.']);
        }

        return ['exists' => true, 'hash' => $hash];
    }

    private function writeBackup(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) || ! is_writable($directory) || ($temporary = tempnam($directory, '.orbit-backup-')) === false || ! chmod($temporary, 0600)) {
            throw new RuntimeException('The backup could not be written.');
        }
        try {
            $file = fopen($temporary, 'wb');
            if (! $file || fwrite($file, $contents) !== strlen($contents) || ! fflush($file) || ! fclose($file) || ! rename($temporary, $path)) {
                if (is_resource($file)) {
                    fclose($file);
                }
                throw new RuntimeException('The backup could not be written.');
            }
        } finally {
            unset($contents);
            if (isset($temporary) && file_exists($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function workspaceFingerprint(): string
    {
        return hash('sha256', json_encode([
            'projects' => DB::table('projects')->orderBy('id')->get(['id', 'revision']),
            'connections' => DB::table('provider_connections')->orderBy('id')->get(['id', 'revision']),
        ], JSON_THROW_ON_ERROR));
    }

    private function snapshot(string $sourceHash): string
    {
        $database = DB::connection()->getDatabaseName();
        if (! is_string($database) || ! is_file($database)) {
            throw new RuntimeException('A private rollback snapshot could not be created.');
        }
        $directory = storage_path('app/restore-snapshots');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('A private rollback snapshot could not be created.');
        }
        $id = (string) Str::uuid7();
        $path = $directory.'/'.$id.'.sqlite';
        DB::table('restore_states')->insert(['id' => $id, 'phase' => 'Prepared', 'source_hash' => $sourceHash, 'snapshot_path' => $path, 'created_at' => now(), 'updated_at' => now()]);
        try {
            $source = new \SQLite3($database, SQLITE3_OPEN_READONLY);
            $target = new \SQLite3($path);
            if (! $source->backup($target) || ! chmod($path, 0600)) {
                throw new RuntimeException;
            }
            $source->close();
            $target->close();

            return $id;
        } catch (Throwable) {
            DB::table('restore_states')->where('id', $id)->delete();
            if (file_exists($path)) {
                unlink($path);
            }
            throw new RuntimeException('A private rollback snapshot could not be created.');
        }
    }
}
