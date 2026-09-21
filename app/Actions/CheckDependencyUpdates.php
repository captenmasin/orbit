<?php

namespace App\Actions;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class CheckDependencyUpdates
{
    public function handle(array $snapshot): array
    {
        $packages = [];
        $skipped = 0;
        foreach (['composer' => ['composer.json', 'composer.lock'], 'npm' => ['package.json', 'package-lock.json']] as $ecosystem => [$manifest, $lockfile]) {
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
                if (($source['state'] ?? null) !== 'Current' || ($lock['state'] ?? null) !== 'Current' || ! preg_match($validName, $name) || ! is_string($current) || ! preg_match('/\Av?\d+\.\d+\.\d+(?:\.\d+)?(?:[-+][\w.+-]+)?\z/', $current) || preg_match('/\A(?:file:|link:|workspace:|git|https?:|npm:)/', $entry['required'] ?? '')) {
                    $skipped++;

                    continue;
                }
                $packages[$ecosystem.':'.$name] = ['name' => $name, 'ecosystem' => $ecosystem, 'current' => $current];
            }
        }
        // ponytail: cap each check at 100 direct dependencies; queue batches if larger roots become common.
        $skipped += max(0, count($packages) - 100);
        $packages = array_slice($packages, 0, 100, true);
        $responses = Http::pool(function (Pool $pool) use ($packages): array {
            $requests = [];
            foreach ($packages as $key => $package) {
                $url = $package['ecosystem'] === 'composer'
                    ? 'https://repo.packagist.org/p2/'.$package['name'].'.json'
                    : 'https://registry.npmjs.org/'.rawurlencode($package['name']).'/latest';
                $requests[] = $pool->as($key)->acceptJson()->withUserAgent('Orbit dependency checker')
                    ->connectTimeout(2)->timeout(3)->withoutRedirecting()->get($url);
            }

            return $requests;
        }, concurrency: 10);
        $outdated = [];
        $unavailable = 0;
        foreach ($packages as $key => $package) {
            $response = $responses[$key] ?? null;
            $latest = null;
            if ($response instanceof Response && $response->successful()) {
                $payload = $response->json();
                $payload = is_array($payload) ? $payload : [];
                $versions = $package['ecosystem'] === 'composer'
                    ? array_column(is_array($payload['packages'][$package['name']] ?? null) ? $payload['packages'][$package['name']] : [], 'version')
                    : [$payload['version'] ?? null];
                foreach ($versions as $version) {
                    if (is_string($version) && preg_match('/\Av?\d+\.\d+\.\d+(?:\.\d+)?(?:\+[\w.-]+)?\z/', $version)) {
                        $version = preg_replace('/\+.*/', '', ltrim($version, 'v'));
                        if ($latest === null || version_compare($version, $latest, '>')) {
                            $latest = $version;
                        }
                    }
                }
            }
            if ($latest === null) {
                $unavailable++;
            } elseif (version_compare(preg_replace('/\+.*/', '', ltrim($package['current'], 'v')), $latest, '<')) {
                $outdated[] = [...$package, 'latest' => $latest];
            }
        }

        return [
            'fingerprint' => ReadDependencies::fingerprint($snapshot), 'checked_at' => now()->toIso8601String(),
            'checked' => count($packages) - $unavailable, 'unavailable' => $unavailable,
            'skipped' => $skipped, 'packages' => $outdated,
        ];
    }
}
