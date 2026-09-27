<?php

namespace App\Actions;

use App\Models\PackageRoot;
use Illuminate\Support\Facades\DB;

class InvalidateRuntimeResults
{
    public function handle(array $beforePaths, array $afterPaths): void
    {
        $changed = array_filter(ProbeRuntimes::TOOLS, fn (string $tool): bool => ($beforePaths[$tool] ?? null) !== ($afterPaths[$tool] ?? null));
        if (! $changed) {
            return;
        }
        DB::transaction(function () use ($changed): void {
            foreach (PackageRoot::query()->lockForUpdate()->lazyById(100) as $root) {
                $affected = [];
                foreach ($changed as $tool) {
                    if (! empty($root->executable_overrides[$tool])) {
                        continue;
                    }
                    $affected = [...$affected, ...match ($tool) {
                        'php' => ['php', 'composer'],
                        'node' => ['node', 'npm', 'pnpm', 'yarn'],
                        default => [$tool],
                    }];
                }
                if (! $affected) {
                    continue;
                }
                $snapshot = $root->snapshot;
                foreach (array_unique($affected) as $tool) {
                    if (isset($snapshot['runtimes'][$tool])) {
                        $snapshot['runtimes'][$tool]['state'] = 'Stale';
                    }
                }
                $root->forceFill([
                    'snapshot' => $snapshot, 'scan_state' => 'Stale',
                    'scan_error' => 'Runtime defaults changed. Refresh this location to apply them.',
                    'scan_token' => null, 'scan_job_id' => null, 'scan_started_at' => null, 'scan_attempted_at' => null,
                    'dependency_check_token' => null, 'dependency_check_attempted_at' => null,
                ])->save();
            }
        });
    }
}
