<?php

namespace App\Jobs;

use App\Actions\InspectFolder;
use App\Actions\ProbeRuntimes;
use App\Actions\ReadDependencies;
use App\Models\PackageRoot;
use App\Models\ProjectFolder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ScanLocalTarget implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public bool $failOnTimeout = true;

    public function __construct(public string $targetId, public string $token, public string $kind)
    {
        $this->onConnection('database')->onQueue('inspection');
    }

    public function handle(InspectFolder $git, ReadDependencies $dependencies, ProbeRuntimes $runtimes): void
    {
        $model = $this->kind === 'folder' ? ProjectFolder::class : PackageRoot::class;
        $target = $model::find($this->targetId);
        if (! $target || $target->scan_token !== $this->token) {
            return;
        }
        $folderPath = $target instanceof ProjectFolder ? $target->path : $target->folder->path;
        $revision = $target->revision;
        $claimed = $model::whereKey($target->id)->where('scan_token', $this->token)->where('scan_state', 'Queued')
            ->update(['scan_state' => 'Scanning', 'scan_started_at' => now()]);
        if (! $claimed) {
            return;
        }

        try {
            if ($target instanceof ProjectFolder) {
                $result = $git->gitMetadata($folderPath);
                $values = $result['scan_error']
                    ? ['scan_state' => 'Stale', 'scan_error' => $result['scan_error']]
                    : [...$result, 'scan_state' => 'Current'];
            } else {
                $path = $target->resolvePath();
                $snapshot = $dependencies->handle($path, $target->snapshot ?? []);
                $snapshot['runtimes'] = $runtimes->handle($path, $target->executable_overrides ?? [], $target->snapshot['runtimes'] ?? []);
                $partial = collect($snapshot['files'])->contains(fn (array $source): bool => ! in_array($source['state'], ['Current', 'Missing file']));
                $values = [
                    'snapshot' => $snapshot, 'scan_state' => $partial ? 'Partial' : 'Current',
                    'scan_error' => null, 'scanned_at' => now(),
                ];
            }
        } catch (RuntimeException $exception) {
            $safe = ['Missing folder', 'Permission denied', 'Root is outside its linked folder'];
            $values = ['scan_state' => 'Stale', 'scan_error' => in_array($exception->getMessage(), $safe) ? $exception->getMessage() : 'Inspection failed'];
        } catch (Throwable) {
            $values = ['scan_state' => 'Stale', 'scan_error' => 'Inspection failed'];
        }

        DB::transaction(function () use ($model, $folderPath, $revision, $values): void {
            $current = $model::find($this->targetId);
            if (! $current || $current->scan_token !== $this->token) {
                return;
            }
            $currentPath = $current instanceof ProjectFolder ? $current->path : $current->folder->path;
            if ($currentPath !== $folderPath || $current->revision !== $revision) {
                return;
            }
            $current->forceFill([...$values, 'scan_job_id' => null, 'scan_started_at' => null])->save();
        });
    }

    public function failed(?Throwable $exception): void
    {
        $model = $this->kind === 'folder' ? ProjectFolder::class : PackageRoot::class;
        $model::whereKey($this->targetId)->where('scan_token', $this->token)->update([
            'scan_state' => 'Stale', 'scan_error' => 'Inspection interrupted', 'scan_job_id' => null, 'scan_started_at' => null,
        ]);
    }
}
