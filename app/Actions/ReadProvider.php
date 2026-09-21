<?php

namespace App\Actions;

use App\Models\Repository;
use Carbon\CarbonImmutable;
use RuntimeException;
use SensitiveParameter;

class ReadProvider
{
    public function __construct(private ProviderHttp $http) {}

    public function identity(string $provider, #[SensitiveParameter] string $token): array
    {
        $data = $this->http->get($provider, '/user', $token)['data'];

        return ['account_id' => $this->identifier($data['id'] ?? null), 'login' => $this->text($data[$provider === 'github' ? 'login' : 'username'] ?? null)];
    }

    public function repository(string $provider, string $name, #[SensitiveParameter] string $token): array
    {
        if (! preg_match('~^[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)+$~D', $name) || str_contains($name, '..') || strlen($name) > 255 || ($provider === 'github' && substr_count($name, '/') !== 1)) {
            throw new RuntimeException('Enter a repository as owner/name or group/project.');
        }
        $path = $provider === 'github' ? '/repos/'.$name : '/projects/'.rawurlencode($name);

        return $this->metadata($provider, $this->http->get($provider, $path, $token)['data']);
    }

    public function resource(Repository $repository, string $resource, int $page, ?string $etag, #[SensitiveParameter] string $token): array
    {
        $provider = $repository->providerConnection->provider;
        $base = ($provider === 'github' ? '/repositories/' : '/projects/').$repository->provider_repository_id;
        if ($resource === 'overview') {
            $metadata = $this->metadata($provider, $this->http->get($provider, $base, $token)['data']);
            if ($metadata['provider_repository_id'] !== $repository->provider_repository_id) {
                throw new RuntimeException('Invalid provider response');
            }
            $commit = null;
            if ($metadata['default_branch']) {
                try {
                    $result = $this->http->get($provider, $base.($provider === 'github' ? '/commits' : '/repository/commits'), $token, [
                        $provider === 'github' ? 'sha' : 'ref_name' => $metadata['default_branch'], 'per_page' => 1,
                    ]);
                    $this->list($result['data']);
                    if ($result['data']) {
                        $row = $result['data'][0];
                        $commit = ['sha' => $this->sha($row[$provider === 'github' ? 'sha' : 'id'] ?? null),
                            'title' => $this->text($provider === 'github' ? ($row['commit']['message'] ?? null) : ($row['title'] ?? null)),
                            'committed_at' => $this->date($provider === 'github' ? ($row['commit']['committer']['date'] ?? null) : ($row['committed_date'] ?? null)),
                            'url' => $this->url($provider, $row[$provider === 'github' ? 'html_url' : 'web_url'] ?? null)];
                    }
                } catch (RuntimeException $exception) {
                    if ($exception->getCode() !== 409) {
                        throw $exception;
                    }
                }
            }

            return ['payload' => [...$metadata, 'commit' => $commit], 'status' => 200, 'etag' => null, 'next_page' => null];
        }
        $overview = $repository->providerSnapshots()->where('resource', 'overview')->first()?->payload;
        $sha = $overview['commit']['sha'] ?? null;
        $query = ['per_page' => 30, 'page' => $page];
        $path = match ($resource) {
            'issues' => '/issues',
            'requests' => $provider === 'github' ? '/pulls' : '/merge_requests',
            'checks' => $provider === 'github' ? '/actions/runs' : '/pipelines',
            'statuses' => '/commits/'.$sha.'/statuses',
            default => throw new RuntimeException('Invalid resource'),
        };
        if (in_array($resource, ['checks', 'statuses'])) {
            if (! $sha) {
                return ['payload' => ['items' => [], 'sha' => null, 'branch' => $repository->default_branch], 'status' => 200, 'etag' => null, 'next_page' => null];
            }
            $this->sha($sha);
            if ($provider === 'gitlab') {
                $query += ['sha' => $sha, 'ref' => $repository->default_branch];
            } elseif ($resource === 'checks') {
                $query += ['head_sha' => $sha, 'branch' => $repository->default_branch];
            }
        } else {
            $query += ['state' => $provider === 'github' ? 'open' : 'opened', 'sort' => 'updated', 'direction' => 'desc'];
            if ($provider === 'gitlab') {
                unset($query['direction']);
                $query['order_by'] = 'updated_at';
                $query['sort'] = 'desc';
                $query['scope'] = 'all';
            }
        }
        if (in_array($resource, ['checks', 'statuses'])) {
            $previous = $repository->providerSnapshots()->where('resource', $resource)->first()?->payload;
            if (($previous['sha'] ?? null) !== $sha || ($provider === 'github' && $resource === 'checks' && ($previous['source'] ?? null) !== 'actions')) {
                $etag = null;
            }
        }
        $result = $this->http->get($provider, $base.$path, $token, $query, $etag);
        if ($result['status'] === 304) {
            return $result;
        }
        $rows = $provider === 'github' && $resource === 'checks' ? ($result['data']['workflow_runs'] ?? null) : $result['data'];
        $this->list($rows);
        if ($provider === 'github' && $resource === 'issues' && $rows && ! array_filter($rows, fn (array $row): bool => ! isset($row['pull_request'])) && $result['next_page']) {
            $result = $this->http->get($provider, $base.$path, $token, [...$query, 'page' => $result['next_page']]);
            $rows = $result['data'];
            $this->list($rows);
            $result['etag'] = null;
        }
        $items = [];
        foreach ($rows as $row) {
            if ($resource === 'issues' && isset($row['pull_request'])) {
                continue;
            }
            if (in_array($resource, ['checks', 'statuses'])) {
                if (($provider === 'gitlab' && (($row['sha'] ?? '') !== $sha || ($row['ref'] ?? '') !== $repository->default_branch)) || ($provider === 'github' && $resource === 'checks' && (($row['head_sha'] ?? '') !== $sha || ($row['head_branch'] ?? '') !== $repository->default_branch))) {
                    continue;
                }
                $state = $provider === 'github' && $resource === 'checks' ? (($row['status'] ?? '') === 'completed' ? ($row['conclusion'] ?? 'unknown') : ($row['status'] ?? 'unknown')) : ($row['status'] ?? $row['state'] ?? null);
                $items[] = ['id' => $this->identifier($row['id'] ?? null), 'title' => $this->text($row['name'] ?? $row['context'] ?? ($provider === 'gitlab' ? 'Pipeline' : null)), 'state' => $this->text($state),
                    'url' => $resource === 'statuses' ? null : $this->url($provider, $row[$provider === 'github' ? 'html_url' : 'web_url'] ?? null)];
            } else {
                $items[] = ['id' => $this->identifier($row['id'] ?? null), 'number' => $this->identifier($row[$provider === 'github' ? 'number' : 'iid'] ?? null),
                    'title' => $this->text($row['title'] ?? null), 'state' => $this->text($row['state'] ?? null),
                    'updated_at' => $this->date($row['updated_at'] ?? null), 'url' => $this->url($provider, $row[$provider === 'github' ? 'html_url' : 'web_url'] ?? null)];
            }
        }

        return [...$result, 'payload' => ['items' => $items, 'sha' => in_array($resource, ['checks', 'statuses']) ? $sha : null, 'branch' => $repository->default_branch,
            ...($provider === 'github' && $resource === 'checks' ? ['source' => 'actions'] : [])]];
    }

    private function metadata(string $provider, array $data): array
    {
        $branch = $data['default_branch'] ?? null;

        return ['provider_repository_id' => $this->identifier($data['id'] ?? null), 'provider_name' => $this->text($data[$provider === 'github' ? 'full_name' : 'path_with_namespace'] ?? null),
            'default_branch' => $branch === null ? null : $this->text($branch), 'provider_url' => $this->url($provider, $data[$provider === 'github' ? 'html_url' : 'web_url'] ?? null)];
    }

    private function list(mixed $rows): void
    {
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 100) {
            throw new RuntimeException('Invalid provider response');
        }
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new RuntimeException('Invalid provider response');
            }
        }
    }

    private function identifier(mixed $value): string
    {
        if ((! is_int($value) && ! is_string($value)) || ! ctype_digit((string) $value) || strlen((string) $value) > 30) {
            throw new RuntimeException('Invalid provider response');
        }

        return (string) $value;
    }

    private function text(mixed $value): string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 10000 || ! mb_check_encoding($value, 'UTF-8')) {
            throw new RuntimeException('Invalid provider response');
        }

        return mb_substr(explode("\n", $value)[0], 0, 500);
    }

    private function sha(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^[a-f0-9]{40,64}$/D', $value)) {
            throw new RuntimeException('Invalid provider response');
        }

        return $value;
    }

    private function date(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?(?:Z|[+-]\d\d:\d\d)$/D', $value)) {
            throw new RuntimeException('Invalid provider response');
        }

        return CarbonImmutable::parse($value)->utc()->toISOString();
    }

    private function url(string $provider, mixed $url): string
    {
        $parts = is_string($url) ? parse_url($url) : false;
        if (! $parts || strlen($url) > 2048 || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== ($provider === 'github' ? 'github.com' : 'gitlab.com') || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['query']) || preg_match('/[\x00-\x20\\\\]/', $url)) {
            throw new RuntimeException('Invalid provider response');
        }

        return $url;
    }
}
