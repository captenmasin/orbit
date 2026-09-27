<?php

namespace App\Actions;

use App\Jobs\CheckDependenciesForRoot;
use App\Models\PackageRoot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

class QueueDependencyChecks
{
    public function handle(): void
    {
        $cutoff = now()->subHours(3);
        $roots = PackageRoot::query()->select('id')->where(function (Builder $query) use ($cutoff): void {
            $query->whereNull('dependency_check_attempted_at')->orWhere('dependency_check_attempted_at', '<=', $cutoff);
        })->lazyById(100);

        foreach ($roots as $root) {
            DB::transaction(function () use ($root, $cutoff): void {
                $current = PackageRoot::query()->lockForUpdate()->find($root->id);
                if (! $current || $current->dependency_check_attempted_at?->isAfter($cutoff)) {
                    return;
                }
                $attemptedAt = now()->toDateTimeString();
                $token = (string) Str::uuid();
                Queue::connection('database')->push(new CheckDependenciesForRoot($current->id, $attemptedAt, $token), '', 'dependencies');
                $current->forceFill(['dependency_check_attempted_at' => $attemptedAt, 'dependency_check_token' => $token])->save();
            });
        }
    }
}
