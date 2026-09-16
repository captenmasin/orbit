<?php

namespace App\Actions;

use App\Jobs\ScanLocalTarget;
use App\Models\PackageRoot;
use App\Models\ProjectFolder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

class QueueInspection
{
    public function handle(ProjectFolder|PackageRoot $target, bool $onlyStale = false): bool
    {
        return DB::transaction(function () use ($target, $onlyStale): bool {
            $target = $target->fresh();
            if (! $target) {
                return false;
            }
            if ($target->scan_state === 'Queued' && (
                ($target->scan_job_id && DB::table('jobs')->where('id', $target->scan_job_id)->exists())
                || $target->scan_attempted_at?->isAfter(now()->subSeconds(90))
            )) {
                return false;
            }
            if ($target->scan_state === 'Scanning' && $target->scan_started_at?->isAfter(now()->subSeconds(90))) {
                return false;
            }
            if ($onlyStale && ! in_array($target->scan_state, ['Queued', 'Scanning']) && $target->scan_attempted_at?->isAfter(now()->subMinutes(5))) {
                return false;
            }
            $token = (string) Str::uuid();
            $job = new ScanLocalTarget($target->id, $token, $target instanceof ProjectFolder ? 'folder' : 'root');
            $jobId = Queue::connection('database')->push($job, '', 'inspection');
            $target->forceFill([
                'scan_token' => $token, 'scan_job_id' => $jobId, 'scan_state' => 'Queued',
                'scan_error' => null, 'scan_attempted_at' => now(), 'scan_started_at' => null,
            ])->save();

            return true;
        });
    }
}
