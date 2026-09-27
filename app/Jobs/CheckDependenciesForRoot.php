<?php

namespace App\Jobs;

use App\Actions\CheckDependencies;
use App\Models\PackageRoot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Validation\ValidationException;

class CheckDependenciesForRoot implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 75;

    public bool $failOnTimeout = true;

    public function __construct(public string $rootId, public string $attemptedAt, public ?string $token = null)
    {
        $this->onConnection('database')->onQueue('dependencies');
    }

    public function handle(CheckDependencies $check): void
    {
        $root = PackageRoot::with('folder')->find($this->rootId);
        if (! $root || $root->dependency_check_attempted_at?->toDateTimeString() !== $this->attemptedAt || $root->dependency_check_token !== ($this->token ?? null)) {
            return;
        }
        try {
            $check->handle($root);
        } catch (ValidationException) {
            // Missing or changed locations keep their existing results for the next check.
        }
    }
}
