<?php

namespace App\Actions;

use App\Jobs\RefreshProviderResource;
use App\Models\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QueueProviderRefresh
{
    public function handle(Repository $repository, string $resource = 'overview', bool $onlyStale = false, bool $more = false): bool
    {
        return DB::transaction(function () use ($repository, $resource, $onlyStale, $more): bool {
            $repository = $repository->fresh('providerConnection');
            $connection = $repository?->providerConnection;
            if (! $connection || $connection->state === 'Token required' || $connection->retry_at?->isFuture()) {
                return false;
            }
            $snapshot = $repository->providerSnapshots()->firstOrCreate(['resource' => $resource]);
            if (in_array($snapshot->state, ['Queued', 'Refreshing']) && $snapshot->attempted_at?->isAfter(now()->subSeconds(90))) {
                return false;
            }
            if ($onlyStale && ($snapshot->state === 'Access unavailable' || $snapshot->attempted_at?->isAfter(now()->subMinutes(5)))) {
                return false;
            }
            if ($more && (! $snapshot->next_page || count($snapshot->payload['items'] ?? []) >= 1000)) {
                return false;
            }
            $snapshot->forceFill(['request_token' => (string) Str::uuid(), 'state' => 'Queued', 'error' => null,
                'attempted_at' => now(), 'requested_page' => $more ? $snapshot->next_page : 1])->save();
            RefreshProviderResource::dispatch($snapshot->id, $snapshot->request_token, $repository->provider_revision, $connection->revision);

            return true;
        });
    }
}
