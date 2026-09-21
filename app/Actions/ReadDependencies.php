<?php

namespace App\Actions;

use RuntimeException;
use stdClass;
use Throwable;

class ReadDependencies
{
    public const MANIFEST_LIMIT = 262144;

    public const LOCK_LIMIT = 16777216;

    public const ENTRY_LIMIT = 20000;

    public function handle(string $path, array $previous = []): array
    {
        $files = [];
        foreach (['composer.json', 'composer.lock', 'package.json', 'package-lock.json'] as $file) {
            $source = ['file' => $file, 'state' => 'Current', 'scanned_at' => now()->toIso8601String(), 'entries' => [], 'requirements' => []];
            try {
                $data = $this->readJson($path, $file, str_ends_with($file, '.lock') || $file === 'package-lock.json' ? self::LOCK_LIMIT : self::MANIFEST_LIMIT);
                $source = [...$source, ...match ($file) {
                    'composer.json' => $this->manifest($data, ['require' => 'Production', 'require-dev' => 'Development']),
                    'package.json' => $this->manifest($data, ['dependencies' => 'Production', 'devDependencies' => 'Development', 'peerDependencies' => 'Peer', 'optionalDependencies' => 'Optional']),
                    'composer.lock' => $this->composerLock($data),
                    'package-lock.json' => $this->npmLock($data),
                }];
            } catch (Throwable $exception) {
                $state = $exception instanceof RuntimeException ? $exception->getMessage() : 'Malformed file';
                $source = [...($previous['files'][$file] ?? $source), 'state' => $state];
                if (! isset($previous['files'][$file])) {
                    $source['scanned_at'] = null;
                }
            }
            $files[$file] = $source;
        }
        $unsupported = array_values(array_filter(['yarn.lock', 'pnpm-lock.yaml'], fn ($file) => file_exists($path.'/'.$file)));

        $snapshot = ['version' => 1, 'path' => $path, 'files' => $files, 'unsupported_lockfiles' => $unsupported];

        return [...$snapshot, 'fingerprint' => self::fingerprint($snapshot)];
    }

    public static function fingerprint(array $snapshot): string
    {
        $files = array_map(fn (array $file): array => array_intersect_key($file, array_flip(['state', 'entries', 'requirements'])), $snapshot['files'] ?? []);

        return hash('sha256', json_encode($files, JSON_THROW_ON_ERROR));
    }

    public function readJson(string $path, string $file, int $limit = self::MANIFEST_LIMIT): stdClass
    {
        $candidate = $path.'/'.$file;
        if (! file_exists($candidate)) {
            throw new RuntimeException('Missing file');
        }
        $resolved = realpath($candidate);
        if (! $resolved || ! str_starts_with($resolved, rtrim($path, '/').'/') || ! is_file($resolved)) {
            throw new RuntimeException('Source is outside the package root');
        }
        if (! is_readable($resolved)) {
            throw new RuntimeException('Permission denied');
        }
        $stream = @fopen($resolved, 'rb');
        if (! $stream) {
            throw new RuntimeException('Permission denied');
        }
        try {
            $json = stream_get_contents($stream, $limit + 1);
        } finally {
            fclose($stream);
        }
        if ($json === false) {
            throw new RuntimeException('Unreadable file');
        }
        if (strlen($json) > $limit) {
            throw new RuntimeException('File too large');
        }
        // Bound JSON allocation before decoding; the parsed package limit is lower.
        if (substr_count($json, '{') + substr_count($json, '[') > 100000) {
            throw new RuntimeException('Too many entries');
        }
        try {
            $data = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('Malformed file');
        }
        if (! $data instanceof stdClass) {
            throw new RuntimeException('Malformed file');
        }

        return $data;
    }

    private function manifest(stdClass $data, array $groups): array
    {
        $entries = [];
        foreach ($groups as $key => $scope) {
            foreach ($this->object($data->$key ?? new stdClass) as $name => $constraint) {
                $entries[] = ['name' => $this->text($name, 255), 'required' => $this->text($constraint), 'scope' => $scope];
                $this->limit($entries);
            }
        }
        $requirements = [];
        foreach ($this->object($data->engines ?? new stdClass) as $name => $version) {
            $requirements[$this->text($name, 255)] = $this->text($version);
        }
        if (isset($data->packageManager)) {
            $requirements['packageManager'] = $this->text($data->packageManager);
        }

        return ['entries' => $entries, 'requirements' => $requirements];
    }

    private function composerLock(stdClass $data): array
    {
        if (! property_exists($data, 'packages')) {
            throw new RuntimeException('Malformed file');
        }
        $entries = [];
        foreach (['packages' => 'Production', 'packages-dev' => 'Development'] as $key => $scope) {
            $packages = $data->$key ?? [];
            if (! is_array($packages)) {
                throw new RuntimeException('Malformed file');
            }
            foreach ($packages as $package) {
                if (! $package instanceof stdClass) {
                    throw new RuntimeException('Malformed file');
                }
                $entries[] = [
                    'name' => $this->text($package->name ?? null, 255), 'version' => $this->text($package->version ?? null),
                    'scope' => $scope, 'location' => null, 'link' => null,
                ];
                $this->limit($entries);
            }
        }

        return ['entries' => $entries];
    }

    private function npmLock(stdClass $data): array
    {
        if (! in_array($data->lockfileVersion ?? null, [1, 2, 3], true)) {
            throw new RuntimeException('Unsupported lockfile version');
        }
        $entries = [];
        if ($data->lockfileVersion === 1) {
            $this->npmV1($data->dependencies ?? new stdClass, '', $entries);
        } else {
            foreach ($this->object($data->packages ?? null) as $location => $package) {
                if ($location === '') {
                    if (! $package instanceof stdClass) {
                        throw new RuntimeException('Malformed file');
                    }

                    continue;
                }
                if (! $package instanceof stdClass) {
                    throw new RuntimeException('Malformed file');
                }
                $name = $package->name ?? (str_contains($location, 'node_modules/') ? substr($location, strrpos($location, 'node_modules/') + 13) : basename($location));
                $entries[] = $this->npmEntry($name, $location, $package);
                $this->limit($entries);
            }
        }

        return ['entries' => $entries, 'lockfile_version' => $data->lockfileVersion];
    }

    private function npmV1(mixed $packages, string $parent, array &$entries): void
    {
        foreach ($this->object($packages) as $name => $package) {
            if (! $package instanceof stdClass) {
                throw new RuntimeException('Malformed file');
            }
            $location = ($parent ? $parent.'/' : '').'node_modules/'.$name;
            $entries[] = $this->npmEntry($name, $location, $package);
            $this->limit($entries);
            $this->npmV1($package->dependencies ?? new stdClass, $location, $entries);
        }
    }

    private function npmEntry(string $name, string $location, stdClass $package): array
    {
        foreach (['dev', 'optional', 'link', 'devOptional'] as $flag) {
            if (isset($package->$flag) && ! is_bool($package->$flag)) {
                throw new RuntimeException('Malformed file');
            }
        }
        $link = ($package->link ?? false) ? $this->text($package->resolved ?? null) : null;

        return [
            'name' => $this->text($name, 255), 'location' => $this->text($location, 4096),
            'version' => $link ? null : (isset($package->version) ? $this->text($package->version) : null), 'link' => $link,
            'scope' => ($package->dev ?? false) ? 'Development' : (($package->optional ?? false) ? 'Optional' : 'Production'),
        ];
    }

    private function object(mixed $value): array
    {
        if (! $value instanceof stdClass) {
            throw new RuntimeException('Malformed file');
        }

        return get_object_vars($value);
    }

    private function text(mixed $value, int $limit = 2048): string
    {
        if (! is_string($value) || $value === '' || strlen($value) > $limit || str_contains($value, "\0")) {
            throw new RuntimeException('Malformed file');
        }

        return $value;
    }

    private function limit(array $entries): void
    {
        if (count($entries) > self::ENTRY_LIMIT) {
            throw new RuntimeException('Too many entries');
        }
    }
}
