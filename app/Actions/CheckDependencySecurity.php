<?php

namespace App\Actions;

use App\DependencyVersions;
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
    private const BATCH_SIZE = 500;

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
        if (! empty($snapshot['additional_ecosystems'])) {
            [, $result['incomplete']] = ReadDependencies::additionalPackages($snapshot);
        }
        if (! $packages) {
            return $result;
        }
        $batches = array_chunk($packages, self::BATCH_SIZE);
        $responses = Http::pool(function (Pool $pool) use ($batches): array {
            $requests = [];
            foreach ($batches as $index => $batch) {
                $requests[] = $this->request($pool->as('batch-'.$index))->post('https://api.osv.dev/v1/querybatch', ['queries' => array_map(fn (array $package): array => [
                    'package' => ['name' => $package['name'], 'ecosystem' => DependencyVersions::OSV[$package['ecosystem']]],
                    'version' => $package['ecosystem'] === 'go' ? $package['current'] : ltrim($package['current'], 'v'),
                ], $batch)]);
            }

            return $requests;
        }, concurrency: 3);
        $queries = [];
        foreach ($batches as $index => $batch) {
            $payload = $this->payload($responses['batch-'.$index] ?? null);
            array_push($queries, ...(is_array($payload?->results) && count($payload->results) === count($batch) ? $payload->results : array_fill(0, count($batch), null)));
        }
        $ids = [];
        $findings = [];
        $incomplete = [];
        foreach ($packages as $index => $package) {
            $query = $queries[$index];
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
        // ponytail: cap one check at 50 advisory details; queue detail batches if larger reports become common.
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
            $versionPattern = $ecosystem === 'composer' ? '/\Av?\d+(?:\.\d+){0,3}(?:[-+][\w.+-]+)?\z/' : '/\Av?\d+\.\d+\.\d+(?:\.\d+)?(?:[-+][\w.+-]+)?\z/';
            if ($lockfile === null) {
                $hasNpm = array_any(['package.json', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock', 'bun.lock', 'bun.lockb'], fn (string $file): bool => ($snapshot['files'][$file]['state'] ?? 'Missing file') !== 'Missing file');
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
                if (! is_string($name) || ! preg_match($pattern, $name) || ! is_string($version) || ! preg_match($versionPattern, $version) || ! empty($entry['link'])) {
                    $skipped++;

                    continue;
                }
                $packages[$ecosystem.':'.$name.':'.$version] = ['name' => $name, 'ecosystem' => $ecosystem, 'current' => $version];
            }
        }

        [$additional, $additionalSkipped] = ReadDependencies::additionalPackages($snapshot);
        $skipped += $additionalSkipped;
        foreach ($additional as $package) {
            $packages[$package['ecosystem'].':'.$package['name'].':'.$package['current']] = $package;
        }

        return [array_values($packages), $skipped];
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
            if (! $affected instanceof stdClass || ! is_string($affected->package->name ?? null) || DependencyVersions::name($package['ecosystem'], $affected->package->name) !== $package['name'] || ($affected->package->ecosystem ?? null) !== DependencyVersions::OSV[$package['ecosystem']]) {
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
                    if (DependencyVersions::comparable($package['ecosystem'], $version) && DependencyVersions::comparable($package['ecosystem'], $package['current']) && DependencyVersions::compare($package['ecosystem'], $version, $package['current']) > 0) {
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
        usort($fixed, fn (string $first, string $second): int => DependencyVersions::compare($package['ecosystem'], $first, $second));

        return [
            'id' => $id, 'title' => is_string($details->summary ?? null) && trim($details->summary) !== '' ? mb_substr($details->summary, 0, 500) : $id,
            'severity' => $severity, 'url' => 'https://osv.dev/vulnerability/'.rawurlencode($id), 'fixed_versions' => $fixed,
        ];
    }
}
