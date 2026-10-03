<?php

namespace App\Actions;

use App\DependencyVersions;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

class CheckDependencyUpdates
{
    private const PACKAGE_LIMIT = 500;

    private const RESPONSE_LIMIT = 4194304;

    public function handle(array $snapshot): array
    {
        $packages = [];
        $skipped = 0;
        $npmLockfile = array_key_exists('npm_lockfile', $snapshot) ? $snapshot['npm_lockfile'] : 'package-lock.json';
        foreach (['composer' => ['composer.json', 'composer.lock'], 'npm' => ['package.json', $npmLockfile]] as $ecosystem => [$manifest, $lockfile]) {
            $versionPattern = $ecosystem === 'composer' ? '/\Av?\d+(?:\.\d+){0,3}(?:[-+][\w.+-]+)?\z/' : '/\Av?\d+\.\d+\.\d+(?:\.\d+)?(?:[-+][\w.+-]+)?\z/';
            $source = $snapshot['files'][$manifest] ?? [];
            $lock = $snapshot['files'][$lockfile] ?? [];
            $lockedPackages = collect($lock['entries'] ?? [])->keyBy($ecosystem === 'composer' ? 'name' : 'location');
            foreach (collect($source['entries'] ?? [])->unique('name') as $entry) {
                $name = $entry['name'];
                if ($ecosystem === 'composer' && ! str_contains($name, '/')) {
                    continue;
                }
                $validName = $ecosystem === 'composer' ? '/\A[a-z0-9_.-]+\/[a-z0-9_.-]+\z/i' : '/\A(?:@[a-z0-9_.-]+\/)?[a-z0-9_.-]+\z/i';
                $locked = $lockedPackages->get($ecosystem === 'composer' ? $name : 'node_modules/'.$name);
                $current = $locked['version'] ?? null;
                if (($source['state'] ?? null) !== 'Current' || ($lock['state'] ?? null) !== 'Current' || ! preg_match($validName, $name) || ! is_string($current) || ! preg_match($versionPattern, $current) || ! empty($entry['link']) || ! empty($locked['link']) || preg_match('/\A(?:file:|link:|workspace:|git|https?:|npm:)/', $entry['required'] ?? '')) {
                    $skipped++;

                    continue;
                }
                $packages[$ecosystem.':'.$name] = ['name' => $name, 'ecosystem' => $ecosystem, 'current' => $current];
            }
        }
        [$additional, $additionalSkipped] = ReadDependencies::additionalPackages($snapshot, directOnly: true);
        $skipped += $additionalSkipped;
        foreach ($additional as $package) {
            $packages[$package['ecosystem'].':'.$package['name'].':'.$package['current']] = $package;
        }
        // ponytail: cap each check at 500 direct dependencies; queue batches if larger roots become common.
        $limited = max(0, count($packages) - self::PACKAGE_LIMIT);
        $incomplete = $additionalSkipped + $limited;
        $skipped += $limited;
        $packages = array_slice($packages, 0, self::PACKAGE_LIMIT, true);
        $responses = Http::pool(function (Pool $pool) use ($packages): array {
            $requests = [];
            foreach ($packages as $key => $package) {
                $url = $this->registryUrl($package['ecosystem'], $package['name']);
                $requests[] = $pool->as($key)->acceptJson()->withUserAgent('Orbit dependency checker')
                    ->connectTimeout(2)->timeout(3)->withoutRedirecting()->withOptions([
                        'on_headers' => function (ResponseInterface $response): void {
                            if ((int) $response->getHeaderLine('Content-Length') > self::RESPONSE_LIMIT) {
                                throw new RuntimeException('Registry response is too large.');
                            }
                        },
                        'progress' => function (float $total, float $downloaded): void {
                            if (max($total, $downloaded) > self::RESPONSE_LIMIT) {
                                throw new RuntimeException('Registry response is too large.');
                            }
                        },
                    ])->get($url);
            }

            return $requests;
        }, concurrency: 10);
        $outdated = [];
        $unavailable = 0;
        foreach ($packages as $key => $package) {
            $response = $responses[$key] ?? null;
            $latest = null;
            if ($response instanceof Response && $response->successful() && strlen($response->body()) <= self::RESPONSE_LIMIT) {
                try {
                    $payload = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR);
                } catch (Throwable) {
                    $payload = [];
                }
                $payload = is_array($payload) ? $payload : [];
                $versions = $this->registryVersions($package['ecosystem'], $package['name'], $payload);
                foreach ($versions as $version) {
                    if (DependencyVersions::valid($package['ecosystem'], $version, stable: true)) {
                        $version = ltrim($version, 'v');
                        if ($latest === null || DependencyVersions::compare($package['ecosystem'], $version, $latest) > 0) {
                            $latest = $version;
                        }
                    }
                }
            }
            if ($latest === null) {
                $unavailable++;
            } elseif (DependencyVersions::compare($package['ecosystem'], $package['current'], $latest) < 0) {
                $outdated[] = [...$package, 'latest' => $latest];
            }
        }

        $result = [
            'fingerprint' => ReadDependencies::fingerprint($snapshot), 'checked_at' => now()->toIso8601String(),
            'checked' => count($packages) - $unavailable, 'unavailable' => $unavailable,
            'skipped' => $skipped, 'packages' => $outdated,
        ];
        if ($incomplete || ! empty($snapshot['additional_ecosystems'])) {
            $result['incomplete'] = $incomplete;
        }

        return $result;
    }

    private function registryUrl(string $ecosystem, string $name): string
    {
        $encoded = rawurlencode($name);

        return match ($ecosystem) {
            'composer' => 'https://repo.packagist.org/p2/'.$name.'.json',
            'npm' => 'https://registry.npmjs.org/'.$encoded.'/latest',
            'python' => 'https://pypi.org/pypi/'.$encoded.'/json',
            'rust' => 'https://crates.io/api/v1/crates/'.$encoded,
            'go' => 'https://proxy.golang.org/'.implode('/', array_map('rawurlencode', explode('/', preg_replace_callback('/[A-Z]/', fn (array $match): string => '!'.strtolower($match[0]), $name)))).'/@latest',
            'ruby' => 'https://rubygems.org/api/v1/gems/'.$encoded.'.json',
            'nuget' => 'https://api.nuget.org/v3-flatcontainer/'.$encoded.'/index.json',
            'dart' => 'https://pub.dev/api/packages/'.$encoded,
            'maven' => 'https://search.maven.org/solrsearch/select?'.http_build_query(['q' => 'g:"'.explode(':', $name)[0].'" AND a:"'.explode(':', $name)[1].'"', 'rows' => 1, 'wt' => 'json']),
        };
    }

    private function registryVersions(string $ecosystem, string $name, array $payload): array
    {
        return match ($ecosystem) {
            'composer' => array_column(is_array($payload['packages'][$name] ?? null) ? $payload['packages'][$name] : [], 'version'),
            'npm', 'ruby' => [$payload['version'] ?? null],
            'python' => empty($payload['info']['yanked']) ? [$payload['info']['version'] ?? null] : [],
            'rust' => [$payload['crate']['max_stable_version'] ?? null],
            'go' => [$payload['Version'] ?? null],
            'nuget' => is_array($payload['versions'] ?? null) ? $payload['versions'] : [],
            'dart' => empty($payload['isDiscontinued']) ? [$payload['latest']['version'] ?? null] : [],
            'maven' => array_column(is_array($payload['response']['docs'] ?? null) ? $payload['response']['docs'] : [], 'latestVersion'),
        };
    }
}
