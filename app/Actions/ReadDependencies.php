<?php

namespace App\Actions;

use App\DependencyVersions;
use RuntimeException;
use stdClass;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Yaml\Yaml;
use Throwable;

class ReadDependencies
{
    public const MANIFEST_LIMIT = 262144;

    public const LOCK_LIMIT = 16777216;

    public const ENTRY_LIMIT = 20000;

    public const ADDITIONAL_FILES = [
        'requirements.txt' => 'python', 'pyproject.toml' => 'python', 'uv.lock' => 'python', 'poetry.lock' => 'python',
        'Cargo.toml' => 'rust', 'Cargo.lock' => 'rust', 'go.mod' => 'go', 'Gemfile.lock' => 'ruby',
        'packages.lock.json' => 'nuget', 'pubspec.lock' => 'dart', 'pom.xml' => 'maven',
        'gradle.lockfile' => 'maven', 'buildscript-gradle.lockfile' => 'maven',
    ];

    public function handle(string $path, array $previous = []): array
    {
        $files = [];
        foreach (['composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock', 'bun.lock', 'bun.lockb', ...array_keys(self::ADDITIONAL_FILES)] as $file) {
            $source = ['file' => $file, 'state' => 'Current', 'scanned_at' => now()->toIso8601String(), 'entries' => [], 'requirements' => []];
            try {
                $source = [...$source, ...match ($file) {
                    'pnpm-lock.yaml' => $this->pnpmLock($this->readYaml($path, $file)),
                    'yarn.lock' => $this->yarnLock($this->readYaml($path, $file), $files['package.json']['entries'] ?? []),
                    'bun.lockb' => $this->rejectBinaryLock($path, $file),
                    default => isset(self::ADDITIONAL_FILES[$file]) ? $this->additionalSource($path, $file) : $this->jsonSource($path, $file, $files['composer.json']['vcs_repositories'] ?? []),
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
        $manager = $files['package.json']['requirements']['packageManager'] ?? '';
        $locks = ['npm' => 'package-lock.json', 'pnpm' => 'pnpm-lock.yaml', 'yarn' => 'yarn.lock', 'bun' => $files['bun.lock']['state'] === 'Missing file' && $files['bun.lockb']['state'] !== 'Missing file' ? 'bun.lockb' : 'bun.lock'];
        $lockfile = $locks[explode('@', $manager)[0]] ?? null;
        if ($lockfile === null) {
            $present = array_values(array_filter($locks, fn ($file) => $files[$file]['state'] !== 'Missing file'));
            $lockfile = count($present) === 1 ? $present[0] : null;
        }
        $unsupported = array_values(array_filter(['yarn.lock', 'pnpm-lock.yaml', 'bun.lock', 'bun.lockb'], fn ($file) => ($lockfile === null || $file === $lockfile) && in_array($files[$file]['state'], ['Unsupported lockfile version', 'Unsupported lockfile format', 'Parser unavailable'])));
        foreach (self::ADDITIONAL_FILES as $file => $ecosystem) {
            if (in_array($files[$file]['state'], ['Unsupported lockfile version', 'Unsupported lockfile format', 'Parser unavailable'], true)) {
                $unsupported[] = $file;
            }
        }
        $snapshot = ['version' => 1, 'path' => $path, 'files' => $files, 'npm_lockfile' => $lockfile, 'unsupported_lockfiles' => $unsupported,
            'additional_ecosystems' => $this->additionalEcosystems($files)];

        return [...$snapshot, 'fingerprint' => self::fingerprint($snapshot)];
    }

    private function additionalSource(string $path, string $file): array
    {
        $limit = in_array($file, ['requirements.txt', 'pyproject.toml', 'Cargo.toml', 'go.mod', 'pom.xml'], true) ? self::MANIFEST_LIMIT : self::LOCK_LIMIT;
        $contents = $this->readFile($path, $file, $limit);
        if (str_contains($contents, "\0") || substr_count($contents, "\n") > 100000) {
            throw new RuntimeException('Malformed file');
        }

        $source = str_ends_with($file, '.toml') || in_array($file, ['uv.lock', 'poetry.lock', 'Cargo.lock'], true)
            ? app(ReadTomlDependencies::class)->handle($file, $contents)
            : app(ReadEcosystemDependencies::class)->handle($file, $contents);
        if ($file === 'pom.xml') {
            $source['requirements']['securityCoverage'] = 'Only declared dependencies; transitive dependencies are not resolved.';
        }

        return $source;
    }

    private function additionalEcosystems(array $files): array
    {
        $present = fn (string $file): bool => $files[$file]['state'] !== 'Missing file';
        $ecosystems = [];
        $pythonLocks = array_values(array_filter(['uv.lock', 'poetry.lock'], $present));
        $pythonManifest = $present('pyproject.toml') ? 'pyproject.toml' : ($present('requirements.txt') ? 'requirements.txt' : null);
        if ($pythonManifest || $pythonLocks) {
            $ecosystems['python'] = ['manifest' => $pythonManifest, 'lockfile' => count($pythonLocks) === 1 ? $pythonLocks[0] : ($pythonLocks ? null : ($pythonManifest === 'requirements.txt' ? $pythonManifest : null))];
        }
        if ($present('Cargo.toml') || $present('Cargo.lock')) {
            $ecosystems['rust'] = ['manifest' => $present('Cargo.toml') ? 'Cargo.toml' : null, 'lockfile' => $present('Cargo.lock') ? 'Cargo.lock' : null];
        }
        foreach (['go' => 'go.mod', 'ruby' => 'Gemfile.lock', 'nuget' => 'packages.lock.json', 'dart' => 'pubspec.lock'] as $ecosystem => $file) {
            if ($present($file)) {
                $ecosystems[$ecosystem] = ['manifest' => $ecosystem === 'go' ? $file : null, 'lockfile' => $file];
            }
        }
        $javaFiles = array_values(array_filter(['pom.xml', 'gradle.lockfile', 'buildscript-gradle.lockfile'], $present));
        if ($javaFiles) {
            $ecosystems['maven'] = ['manifest' => null, 'lockfile' => $javaFiles[0], 'lockfiles' => $javaFiles];
        }

        return $ecosystems;
    }

    /** @return array{0: array, 1: int} */
    public static function additionalPackages(array $snapshot, bool $directOnly = false): array
    {
        $packages = [];
        $skipped = 0;
        foreach ($snapshot['additional_ecosystems'] ?? [] as $ecosystem => $selection) {
            $manifest = $snapshot['files'][$selection['manifest'] ?? ''] ?? null;
            $locks = array_values(array_filter($selection['lockfiles'] ?? [$selection['lockfile'] ?? null]));
            $entries = [];
            foreach ($locks as $file) {
                $source = $snapshot['files'][$file] ?? [];
                if (($source['state'] ?? null) !== 'Current') {
                    $skipped += max(1, count($source['entries'] ?? []));

                    continue;
                }
                if (! $directOnly && ! empty($source['requirements']['securityCoverage'])) {
                    $skipped++;
                }
                array_push($entries, ...($source['entries'] ?? []));
            }
            if (! $locks) {
                $skipped += max(1, count($manifest['entries'] ?? []));

                continue;
            }
            if ($manifest && ($manifest['state'] ?? null) !== 'Current') {
                $skipped += max(1, count($manifest['entries'] ?? []));
                if ($directOnly) {
                    continue;
                }
            }
            if ($directOnly && $manifest && ($selection['manifest'] ?? null) !== ($selection['lockfile'] ?? null)) {
                $resolved = [];
                foreach ($manifest['entries'] ?? [] as $entry) {
                    $name = DependencyVersions::name($ecosystem, $entry['name'] ?? '');
                    $matches = array_values(array_filter($entries, fn (array $locked): bool => DependencyVersions::name($ecosystem, $locked['name'] ?? '') === $name));
                    if (! empty($entry['link']) || count(array_unique(array_column($matches, 'version'))) !== 1) {
                        $skipped++;

                        continue;
                    }
                    $resolved[] = [...$matches[0], 'name' => $entry['name']];
                }
                $entries = $resolved;
            }
            foreach ($entries as $entry) {
                if (! empty($entry['root']) || (! empty($entry['link']) && ($manifest['requirements']['project-name'] ?? null) === ($entry['name'] ?? null))) {
                    continue;
                }
                if ($directOnly && ($entry['identity'] ?? null) === 'Transitive') {
                    continue;
                }
                $name = DependencyVersions::name($ecosystem, $entry['name'] ?? '');
                $version = $entry['version'] ?? null;
                if (! DependencyVersions::validName($ecosystem, $name) || ! DependencyVersions::valid($ecosystem, $version) || ! empty($entry['link']) || ($directOnly && ! DependencyVersions::comparable($ecosystem, $version))) {
                    $skipped++;

                    continue;
                }
                $packages[$ecosystem.':'.$name.':'.$version] = ['name' => $name, 'ecosystem' => $ecosystem, 'current' => $version];
            }
        }

        return [array_values($packages), $skipped];
    }

    private function jsonSource(string $path, string $file, array $composerRepositories = []): array
    {
        $data = $this->readJson($path, $file, in_array($file, ['composer.lock', 'package-lock.json', 'bun.lock']) ? self::LOCK_LIMIT : self::MANIFEST_LIMIT);

        return match ($file) {
            'composer.json' => $this->composerManifest($data),
            'package.json' => $this->manifest($data, ['dependencies' => 'Production', 'devDependencies' => 'Development', 'peerDependencies' => 'Peer', 'optionalDependencies' => 'Optional']),
            'composer.lock' => $this->composerLock($data, $composerRepositories),
            'package-lock.json' => $this->npmLock($data),
            'bun.lock' => $this->bunLock($data),
        };
    }

    public static function fingerprint(array $snapshot): string
    {
        $files = array_map(fn (array $file): array => array_intersect_key($file, array_flip(['state', 'entries', 'requirements', 'vcs_repositories'])), $snapshot['files'] ?? []);

        return hash('sha256', json_encode([$files, array_key_exists('npm_lockfile', $snapshot) ? $snapshot['npm_lockfile'] : 'package-lock.json', $snapshot['additional_ecosystems'] ?? []], JSON_THROW_ON_ERROR));
    }

    public function readJson(string $path, string $file, int $limit = self::MANIFEST_LIMIT): stdClass
    {
        $json = $this->readFile($path, $file, $limit);
        if ($file === 'bun.lock') {
            $strings = '"(?:[^"\\\\]++|\\\\.)*+"(*SKIP)(*F)';
            $json = preg_replace('~'.$strings.'|//[^\r\n]*|/\*.*?\*/~s', ' ', $json);
            $json = $json === null ? null : preg_replace('~'.$strings.'|,\s*(?=[}\]])~s', '', $json);
            if ($json === null) {
                throw new RuntimeException('Malformed file');
            }
        }
        /** Bound JSON allocation before decoding; the parsed package limit is lower. */
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

    private function readFile(string $path, string $file, int $limit): string
    {
        $candidate = $path.'/'.$file;
        if (! file_exists($candidate)) {
            throw new RuntimeException('Missing file');
        }
        $resolved = realpath($candidate);
        if (! $resolved || ! Path::isBasePath(realpath($path) ?: $path, $resolved) || ! is_file($resolved)) {
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

        return $json;
    }

    private function readYaml(string $path, string $file): array
    {
        $yaml = $this->readFile($path, $file, self::LOCK_LIMIT);
        if ($file === 'yarn.lock' && preg_match('/^#\s*yarn lockfile v1\s*$/m', $yaml)) {
            throw new RuntimeException('Unsupported lockfile format');
        }
        if (! class_exists(Yaml::class)) {
            throw new RuntimeException('Parser unavailable');
        }
        if (substr_count($yaml, "\n") > 100000) {
            throw new RuntimeException('Too many entries');
        }
        try {
            $data = Yaml::parse($yaml, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE | Yaml::PARSE_EXCEPTION_ON_ALIAS, 64);
        } catch (Throwable) {
            throw new RuntimeException('Malformed file');
        }
        if (! is_array($data)) {
            throw new RuntimeException('Malformed file');
        }

        return $data;
    }

    private function rejectBinaryLock(string $path, string $file): never
    {
        $this->readFile($path, $file, self::LOCK_LIMIT);
        throw new RuntimeException('Unsupported lockfile format');
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

    private function composerManifest(stdClass $data): array
    {
        $source = $this->manifest($data, ['require' => 'Production', 'require-dev' => 'Development']);
        $repositories = $data->repositories ?? [];
        if (! is_array($repositories) && ! $repositories instanceof stdClass) {
            throw new RuntimeException('Malformed file');
        }
        $urls = [];
        foreach ($repositories as $repository) {
            if ($repository instanceof stdClass && ($repository->type ?? null) === 'vcs') {
                $url = $this->text($repository->url ?? null);
                $urls[$this->repositoryUrl($url)] = $url;
                $this->limit($urls);
            }
        }

        return [...$source, 'vcs_repositories' => $urls];
    }

    private function repositoryUrl(string $url): string
    {
        return preg_replace('/\.git\z/', '', rtrim($url, '/'));
    }

    private function composerLock(stdClass $data, array $repositories = []): array
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
                $link = null;
                if ($repositories && isset($package->source->url)) {
                    $url = $this->text($package->source->url);
                    if (isset($repositories[$this->repositoryUrl($url)])) {
                        $link = $url;
                    }
                }
                $entries[] = [
                    'name' => $this->text($package->name ?? null, 255), 'version' => $this->text($package->version ?? null),
                    'scope' => $scope, 'location' => null, 'link' => $link,
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

    private function pnpmLock(array $data): array
    {
        if (! in_array($data['lockfileVersion'] ?? null, ['9.0', 9, 9.0], true)) {
            throw new RuntimeException('Unsupported lockfile version');
        }
        $entries = [];
        foreach ($this->mapping($data['importers'] ?? null) as $importer => $packages) {
            $importer = $this->text($importer, 4096);
            if (str_starts_with($importer, '/') || in_array('..', explode('/', $importer), true)) {
                throw new RuntimeException('Malformed file');
            }
            $packages = $this->mapping($packages);
            foreach (['dependencies' => 'Production', 'devDependencies' => 'Development', 'optionalDependencies' => 'Optional'] as $key => $scope) {
                foreach ($this->mapping($packages[$key] ?? []) as $name => $package) {
                    $package = $this->mapping($package);
                    $version = $this->text($package['version'] ?? null);
                    $link = preg_match('/\A(?:link:|file:|workspace:)/', $version) ? $version : null;
                    $entries[] = [
                        'name' => $this->text($name, 255), 'version' => $link ? null : explode('(', $version, 2)[0],
                        'required' => $this->text($package['specifier'] ?? null), 'scope' => $scope,
                        'location' => ($importer === '.' ? '' : $importer.'/').'node_modules/'.$name, 'link' => $link, 'importer' => $importer,
                    ];
                    $this->limit($entries);
                }
            }
        }
        foreach ($this->mapping($data['packages'] ?? []) as $resolution => $package) {
            $this->mapping($package);
            if (! preg_match('/\A((?:@[^\/]+\/)?[^@]+)@(.+)\z/', $resolution, $match)) {
                throw new RuntimeException('Unsupported package resolution');
            }
            $entries[] = [
                'name' => $this->text($match[1], 255), 'version' => $this->text(explode('(', $match[2], 2)[0]),
                'scope' => 'Unknown', 'location' => $this->text('lock:'.$resolution, 4096), 'link' => null,
            ];
            $this->limit($entries);
        }

        return ['entries' => $entries, 'lockfile_version' => '9.0'];
    }

    private function yarnLock(array $data, array $declared): array
    {
        $metadata = $this->mapping($data['__metadata'] ?? null);
        if (! in_array($metadata['version'] ?? null, [4, 5, 6, 7, 8], true)) {
            throw new RuntimeException('Unsupported lockfile version');
        }
        $entries = [];
        $direct = [];
        foreach ($declared as $dependency) {
            $direct[$dependency['name'].'@'.$dependency['required']] = $dependency;
            $direct[$dependency['name'].'@npm:'.$dependency['required']] = $dependency;
        }
        foreach ($data as $descriptors => $package) {
            if ($descriptors === '__metadata') {
                continue;
            }
            $package = $this->mapping($package);
            $resolution = $this->text($package['resolution'] ?? null);
            if (! preg_match('/\A((?:@[^\/]+\/)?[^@]+)@(.+)\z/', $resolution, $match)) {
                throw new RuntimeException('Malformed file');
            }
            $link = str_starts_with($match[2], 'npm:') ? null : $match[2];
            $entry = [
                'name' => $this->text($match[1], 255), 'version' => $link ? null : $this->text($package['version'] ?? null),
                'scope' => 'Unknown', 'location' => $this->text('lock:'.$resolution, 4096), 'link' => $link,
            ];
            $entries[] = $entry;
            $this->limit($entries);
            $keys = explode(', ', $this->text($descriptors, self::LOCK_LIMIT));
            foreach ($keys as $key) {
                if (isset($direct[$key])) {
                    $dependency = $direct[$key];
                    $entries[] = [...$entry, 'location' => 'node_modules/'.$dependency['name'], 'scope' => $dependency['scope']];
                    $this->limit($entries);
                }
            }
        }

        return ['entries' => $entries, 'lockfile_version' => $metadata['version']];
    }

    private function bunLock(stdClass $data): array
    {
        if (! in_array($data->lockfileVersion ?? null, [0, 1, 2], true)) {
            throw new RuntimeException('Unsupported lockfile version');
        }
        $workspaces = $this->object($data->workspaces ?? null);
        $root = $this->object($workspaces[''] ?? null);
        $scopes = [];
        foreach (['dependencies' => 'Production', 'devDependencies' => 'Development', 'peerDependencies' => 'Peer', 'optionalDependencies' => 'Optional'] as $key => $scope) {
            foreach ($this->object($root[$key] ?? new stdClass) as $name => $constraint) {
                $this->text($constraint);
                $scopes[$name] = $scope;
            }
        }
        $entries = [];
        foreach ($this->object($data->packages ?? null) as $location => $package) {
            if (! is_array($package) || ! is_string($package[0] ?? null) || ! preg_match('/\A((?:@[^\/]+\/)?[^@]+)@(.+)\z/', $package[0], $match)) {
                throw new RuntimeException('Malformed file');
            }
            $version = $this->text($match[2]);
            $link = preg_match('/\Av?\d+\.\d+\.\d+(?:[-+][\w.+-]+)?\z/', $version) ? null : $version;
            $entries[] = [
                'name' => $this->text($match[1], 255), 'version' => $link ? null : $version, 'link' => $link,
                'scope' => $scopes[$location] ?? 'Unknown',
                'location' => $this->text(preg_match('/\A(?:@[^\/]+\/)?[^\/]+\z/', $location) ? 'node_modules/'.$location : 'lock:'.$location, 4096),
            ];
            $this->limit($entries);
        }

        return ['entries' => $entries, 'lockfile_version' => $data->lockfileVersion];
    }

    private function mapping(mixed $value): array
    {
        if (! is_array($value)) {
            throw new RuntimeException('Malformed file');
        }

        return $value;
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
