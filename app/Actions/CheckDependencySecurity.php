<?php

namespace App\Actions;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use stdClass;

class CheckDependencySecurity
{
    private const PACKAGE_LIMIT = 500;

    private const ADVISORY_LIMIT = 50;

    private const RESPONSE_LIMIT = 1048576;

    /**
     * @return array{fingerprint: string, checked_at: string, checked: int, unavailable: int, skipped: int, packages: array}
     */
    public function handle(array $snapshot): array
    {
        [$packages, $skipped] = $this->packages($snapshot);
        $result = [
            'fingerprint' => ReadDependencies::fingerprint($snapshot), 'checked_at' => now()->toIso8601String(),
            'checked' => 0, 'unavailable' => 0, 'skipped' => $skipped, 'packages' => [],
        ];
        if (! $packages) {
            return $result;
        }
        $responses = Http::pool(fn (Pool $pool): array => [
            $this->request($pool->as('batch'))->post('https://api.osv.dev/v1/querybatch', ['queries' => array_map(fn (array $package): array => [
                'package' => ['name' => $package['name'], 'ecosystem' => $package['ecosystem'] === 'composer' ? 'Packagist' : 'npm'],
                'version' => ltrim($package['current'], 'v'),
            ], $packages)]),
        ], concurrency: 1);
        $batch = $this->payload($responses['batch'] ?? null);
        if (! is_array($batch?->results) || count($batch->results) !== count($packages)) {
            return [...$result, 'unavailable' => count($packages)];
        }
        $ids = [];
        $findings = [];
        $incomplete = [];
        foreach ($packages as $index => $package) {
            $query = $batch->results[$index];
            if (! $query instanceof stdClass || (property_exists($query, 'vulns') && ! is_array($query->vulns)) || (! property_exists($query, 'vulns') && array_diff(array_keys(get_object_vars($query)), ['next_page_token']))) {
                $incomplete[$index] = true;

                continue;
            }
            if (! empty($query->next_page_token)) {
                $incomplete[$index] = true;
            }
            foreach ($query->vulns ?? [] as $vulnerability) {
                $id = $vulnerability instanceof stdClass ? ($vulnerability->id ?? null) : null;
                if (! is_string($id) || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/', $id)) {
                    $incomplete[$index] = true;

                    continue;
                }
                $findings[$index][$id] = $id;
                $ids[$id] = $id;
            }
        }
        // ponytail: cap one check at 500 package versions and 50 advisory details; queue batches for larger roots.
        $responses = Http::pool(function (Pool $pool) use ($ids): array {
            $requests = [];
            foreach (array_slice($ids, 0, self::ADVISORY_LIMIT, true) as $id) {
                $requests[] = $this->request($pool->as($id))->get('https://api.osv.dev/v1/vulns/'.rawurlencode($id));
            }

            return $requests;
        }, concurrency: 10);
        foreach ($findings as $index => $vulnerabilities) {
            $advisories = [];
            foreach ($vulnerabilities as $id) {
                $details = $this->payload($responses[$id] ?? null);
                $advisory = $this->advisory($id, $details, $packages[$index]);
                if ($advisory === null) {
                    $incomplete[$index] = true;
                    $advisory = ['id' => $id, 'title' => $id, 'severity' => 'Unknown', 'url' => 'https://osv.dev/vulnerability/'.rawurlencode($id), 'fixed_versions' => []];
                }
                $advisories[] = $advisory;
            }
            $result['packages'][] = [...$packages[$index], 'advisories' => $advisories];
        }
        $result['unavailable'] = count($incomplete);
        $result['checked'] = count($packages) - $result['unavailable'];

        return $result;
    }

    /** @return array{0: array, 1: int} */
    private function packages(array $snapshot): array
    {
        $packages = [];
        $skipped = count($snapshot['unsupported_lockfiles'] ?? []);
        $npmLockfile = array_key_exists('npm_lockfile', $snapshot) ? $snapshot['npm_lockfile'] : 'package-lock.json';
        foreach (['composer' => ['composer.json', 'composer.lock'], 'npm' => ['package.json', $npmLockfile]] as $ecosystem => [$manifest, $lockfile]) {
            if ($lockfile === null) {
                $hasNpm = array_any(array_keys($snapshot['files'] ?? []), fn (string $file): bool => ! in_array($file, ['composer.json', 'composer.lock'], true)
                    && ($snapshot['files'][$file]['state'] ?? 'Missing file') !== 'Missing file');
                if ($hasNpm) {
                    $skipped += max(1, count($snapshot['files'][$manifest]['entries'] ?? []));
                }

                continue;
            }
            $lock = $snapshot['files'][$lockfile] ?? [];
            $entries = $lock['entries'] ?? [];
            if (($lock['state'] ?? null) !== 'Current') {
                $declared = array_filter($snapshot['files'][$manifest]['entries'] ?? [], fn (array $entry): bool => $ecosystem === 'npm' || str_contains($entry['name'] ?? '', '/'));
                if ($declared || $entries || ($snapshot['files'][$manifest]['state'] ?? 'Missing file') !== 'Missing file' || ($lock['state'] ?? 'Missing file') !== 'Missing file') {
                    $skipped += max(1, count($entries), count($declared));
                }

                continue;
            }
            foreach ($entries as $entry) {
                $name = $entry['name'] ?? null;
                $version = $entry['version'] ?? null;
                $pattern = $ecosystem === 'composer' ? '/\A[a-z0-9_.-]+\/[a-z0-9_.-]+\z/i' : '/\A(?:@[a-z0-9_.-]+\/)?[a-z0-9_.-]+\z/i';
                if (! is_string($name) || ! preg_match($pattern, $name) || ! is_string($version) || ! preg_match('/\Av?\d+\.\d+\.\d+(?:\.\d+)?(?:[-+][\w.+-]+)?\z/', $version) || ! empty($entry['link'])) {
                    $skipped++;

                    continue;
                }
                $packages[$ecosystem.':'.$name.':'.$version] = ['name' => $name, 'ecosystem' => $ecosystem, 'current' => $version];
            }
        }
        $skipped += max(0, count($packages) - self::PACKAGE_LIMIT);

        return [array_values(array_slice($packages, 0, self::PACKAGE_LIMIT)), $skipped];
    }

    private function request(PendingRequest $request): PendingRequest
    {
        return $request->acceptJson()->withUserAgent('Orbit dependency checker')->connectTimeout(2)->timeout(3)->withoutRedirecting()->withOptions([
            'on_headers' => function (ResponseInterface $response): void {
                if ((int) $response->getHeaderLine('Content-Length') > self::RESPONSE_LIMIT) {
                    throw new RuntimeException('Security response is too large.');
                }
            },
            'progress' => function (float $total, float $downloaded): void {
                if (max($total, $downloaded) > self::RESPONSE_LIMIT) {
                    throw new RuntimeException('Security response is too large.');
                }
            },
        ]);
    }

    private function payload(mixed $response): ?stdClass
    {
        if (! $response instanceof Response || ! $response->successful() || strlen($response->body()) > self::RESPONSE_LIMIT) {
            return null;
        }
        try {
            $payload = json_decode($response->body(), false, 32, JSON_THROW_ON_ERROR);

            return $payload instanceof stdClass ? $payload : null;
        } catch (JsonException) {
            return null;
        }
    }

    /** @return array{id: string, title: string, severity: string, url: string, fixed_versions: array}|null */
    private function advisory(string $id, ?stdClass $details, array $package): ?array
    {
        if ($details?->id !== $id || ! is_string($details->modified ?? null) || ! is_array($details->affected ?? null)) {
            return null;
        }
        $severity = 'Unknown';
        $levels = ['Unknown' => 0, 'Low' => 1, 'Moderate' => 2, 'High' => 3, 'Critical' => 4];
        $categories = [$details->database_specific->severity ?? null];
        $fixed = [];
        $matched = false;
        foreach ($details->affected as $affected) {
            if (! $affected instanceof stdClass || ($affected->package->name ?? null) !== $package['name'] || ($affected->package->ecosystem ?? null) !== ($package['ecosystem'] === 'composer' ? 'Packagist' : 'npm')) {
                continue;
            }
            $matched = true;
            $categories[] = $affected->database_specific->severity ?? null;
            $categories[] = $affected->ecosystem_specific->severity ?? null;
            foreach (is_array($affected->ranges ?? null) ? $affected->ranges : [] as $range) {
                if (! $range instanceof stdClass || ! in_array($range->type ?? null, ['SEMVER', 'ECOSYSTEM'], true)) {
                    continue;
                }
                foreach (is_array($range->events ?? null) ? $range->events : [] as $event) {
                    $version = $event instanceof stdClass ? ($event->fixed ?? null) : null;
                    if (is_string($version) && preg_match('/\Av?\d+\.\d+\.\d+(?:\.\d+)?(?:[-+][\w.+-]+)?\z/', $version) && version_compare(ltrim($version, 'v'), ltrim($package['current'], 'v'), '>')) {
                        $fixed[$version] = $version;
                    }
                }
            }
        }
        if (! $matched) {
            return null;
        }
        foreach ($categories as $category) {
            $category = is_string($category) ? ucfirst(strtolower($category)) : 'Unknown';
            if (($levels[$category] ?? 0) > $levels[$severity]) {
                $severity = $category;
            }
        }
        $fixed = array_values($fixed);
        usort($fixed, fn (string $first, string $second): int => version_compare(ltrim($first, 'v'), ltrim($second, 'v')));

        return [
            'id' => $id, 'title' => is_string($details->summary ?? null) && trim($details->summary) !== '' ? mb_substr($details->summary, 0, 500) : $id,
            'severity' => $severity, 'url' => 'https://osv.dev/vulnerability/'.rawurlencode($id), 'fixed_versions' => $fixed,
        ];
    }
}
