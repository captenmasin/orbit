<?php

namespace App\Actions;

use App\Models\PackageRoot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CheckDependencies
{
    public function __construct(private ReadDependencies $read, private CheckDependencyUpdates $updates, private CheckDependencySecurity $security) {}

    public function handle(PackageRoot $root): void
    {
        try {
            $path = $root->resolvePath();
            $snapshot = $this->read->handle($path, $root->snapshot ?? []);
        } catch (RuntimeException $exception) {
            DB::transaction(function () use ($root, $exception): void {
                $current = $this->currentRoot($root);
                $this->assertUnchangedTokens($root, $current);
                $current->forceFill([
                    'scan_state' => 'Stale', 'scan_error' => $exception->getMessage(),
                    'scan_token' => null, 'scan_job_id' => null, 'scan_started_at' => null,
                    'scan_attempted_at' => now(), 'dependency_check_attempted_at' => now(), 'dependency_check_token' => null,
                ])->save();
            });
            throw ValidationException::withMessages(['root' => $exception->getMessage()]);
        }
        $checkToken = (string) Str::uuid();
        $attributes = [
            'scan_state' => collect($snapshot['files'])->contains(fn (array $file): bool => ! in_array($file['state'], ['Current', 'Missing file'], true)) ? 'Partial' : 'Current',
            'scan_error' => null, 'scan_token' => $checkToken, 'scan_job_id' => null, 'scan_started_at' => null,
            'scan_attempted_at' => now(), 'scanned_at' => now(), 'dependency_check_attempted_at' => now(), 'dependency_check_token' => null,
        ];
        DB::transaction(function () use ($root, &$snapshot, $attributes): void {
            $current = $this->currentRoot($root);
            $this->assertUnchangedTokens($root, $current);
            $snapshot['runtimes'] = $current->snapshot['runtimes'] ?? [];
            $current->forceFill(['snapshot' => $snapshot, ...$attributes])->save();
        });
        $outdated = $this->updates->handle($snapshot);
        $issues = $this->security->handle($snapshot);
        $error = null;
        try {
            $latestPath = $root->resolvePath();
            $latest = $this->read->handle($latestPath, $snapshot);
            if ($latestPath !== $path) {
                $error = 'This package location changed. Check it again.';
            }
        } catch (RuntimeException $exception) {
            $latest = $snapshot;
            $error = $exception->getMessage();
        }
        $latest['runtimes'] = $snapshot['runtimes'];
        $changed = $error !== null || $latest['fingerprint'] !== $snapshot['fingerprint'];
        $error ??= 'Package files changed during the check. Check this location again.';
        DB::transaction(function () use ($root, $snapshot, $latest, $outdated, $issues, $changed, $error, $checkToken): void {
            $current = $this->currentRoot($root);
            if ($current->scan_token !== $checkToken || ReadDependencies::fingerprint($current->snapshot ?? []) !== $snapshot['fingerprint']) {
                throw ValidationException::withMessages(['root' => 'This package location changed. Check it again.']);
            }
            $current->forceFill($changed ? [
                'snapshot' => $latest, 'scan_state' => 'Stale', 'scan_error' => $error, 'scan_token' => null,
            ] : ['outdated' => $outdated, 'security' => $issues, 'scan_token' => null])->save();
        });
        if ($changed) {
            throw ValidationException::withMessages(['root' => $error]);
        }
    }

    private function assertUnchangedTokens(PackageRoot $root, PackageRoot $current): void
    {
        if ($current->scan_token !== $root->scan_token || $current->dependency_check_token !== $root->dependency_check_token) {
            throw ValidationException::withMessages(['root' => 'This package location changed. Check it again.']);
        }
    }

    private function currentRoot(PackageRoot $root): PackageRoot
    {
        $current = PackageRoot::query()->with('folder')->lockForUpdate()->find($root->id);
        if (! $current || $current->revision !== $root->revision || $current->folder->path !== $root->folder->path) {
            throw ValidationException::withMessages(['root' => 'This package location changed. Check it again.']);
        }

        return $current;
    }
}
