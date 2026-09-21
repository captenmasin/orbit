<?php

namespace App\Jobs;

use App\Actions\ProviderHttp;
use App\Actions\QueueProviderRefresh;
use App\Actions\ReadProvider;
use App\Models\ProviderConnection;
use App\Models\ProviderSnapshot;
use App\Models\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class RefreshProviderResource implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public bool $failOnTimeout = true;

    public function __construct(public string $snapshotId, public string $token, public int $repositoryRevision, public int $connectionRevision)
    {
        $this->onConnection('database')->onQueue('inspection');
    }

    public function handle(ProviderHttp $http, ReadProvider $reader): void
    {
        $snapshot = ProviderSnapshot::with('repository.providerConnection')->find($this->snapshotId);
        if (! $snapshot || $snapshot->request_token !== $this->token || $snapshot->repository->provider_revision !== $this->repositoryRevision || $snapshot->repository->providerConnection?->revision !== $this->connectionRevision) {
            return;
        }
        if (! ProviderSnapshot::whereKey($snapshot->id)->where('request_token', $this->token)->where('state', 'Queued')->update(['state' => 'Refreshing'])) {
            return;
        }
        $repository = $snapshot->repository;
        try {
            $result = $http->using($repository->providerConnection, fn (string $credential): array => $reader->resource(
                $repository, $snapshot->resource, $snapshot->requested_page,
                $snapshot->requested_page === 1 && ! $snapshot->next_page && count($snapshot->payload['items'] ?? []) <= 30 ? $snapshot->etag : null, $credential,
            ));
            $values = ['state' => 'Current', 'error' => null, 'checked_at' => now()];
            if ($result['status'] !== 304) {
                $payload = $result['payload'];
                if ($snapshot->requested_page > 1) {
                    if (($snapshot->payload['sha'] ?? null) !== ($payload['sha'] ?? null)) {
                        throw new RuntimeException('Commit changed. Refresh this resource.');
                    }
                    $payload['items'] = collect([...($snapshot->payload['items'] ?? []), ...$payload['items']])->unique('id')->take(1000)->values()->all();
                }
                $values += ['payload' => $payload, 'etag' => $snapshot->requested_page === 1 ? $result['etag'] : null,
                    'next_page' => count($payload['items'] ?? []) >= 1000 ? null : $result['next_page'], 'succeeded_at' => now()];
            } elseif (! $snapshot->payload) {
                throw new RuntimeException('Invalid provider response');
            }
        } catch (Throwable $exception) {
            $allowed = ['Token required', 'Rate limited', 'Repository unavailable or inaccessible', 'Provider unavailable', 'Provider unavailable or response too large', 'Invalid provider response', 'Connection busy. Try again.', 'Commit changed. Refresh this resource.', 'Native credential storage is unavailable. Open the desktop app and try again.'];
            $message = $exception instanceof RuntimeException && in_array($exception->getMessage(), $allowed) ? $exception->getMessage() : 'Provider refresh failed';
            $values = ['state' => in_array($exception->getCode(), [403, 404]) ? 'Access unavailable' : 'Stale',
                'error' => $exception->getCode() === 403 ? 'Check token permissions and organization approval.' : $message];
            if ($exception->getCode() === 403 && $repository->providerConnection->provider === 'github' && $snapshot->resource === 'checks') {
                $values['error'] = 'GitHub Actions requires Actions: read permission for this repository.';
            }
        }
        DB::transaction(function () use ($snapshot, $repository, $values): void {
            $current = Repository::with('providerConnection')->find($repository->id);
            if (! $current || $current->provider_revision !== $this->repositoryRevision || $current->providerConnection?->revision !== $this->connectionRevision) {
                return;
            }
            $saved = ProviderSnapshot::whereKey($snapshot->id)->where('request_token', $this->token)->update(isset($values['payload']) ? [...$values, 'payload' => json_encode($values['payload'], JSON_THROW_ON_ERROR)] : $values);
            if ($saved && $snapshot->resource === 'overview' && isset($values['payload'])) {
                $payload = $values['payload'];
                $current->forceFill(['provider_name' => $payload['provider_name'], 'default_branch' => $payload['default_branch'],
                    'provider_url' => $payload['provider_url'], 'remote_commit_at' => $payload['commit']['committed_at'] ?? null])->save();
                foreach (['issues', 'requests', 'checks', ...($current->providerConnection->provider === 'github' ? ['statuses'] : [])] as $resource) {
                    app(QueueProviderRefresh::class)->handle($current, $resource, onlyStale: true);
                }
            }
            if ($saved && $values['state'] === 'Current') {
                ProviderConnection::whereKey($current->provider_connection_id)->where('revision', $this->connectionRevision)->update(['state' => 'Current', 'retry_at' => null]);
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        ProviderSnapshot::whereKey($this->snapshotId)->where('request_token', $this->token)->update(['state' => 'Stale', 'error' => 'Refresh interrupted. Try again.']);
    }
}
