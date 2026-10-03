<?php

namespace App\Actions;

use Devium\Toml\Toml;
use RuntimeException;
use Throwable;

class ReadTomlDependencies
{
    public function __construct(private ReadEcosystemDependencies $ecosystems) {}

    /** @return array{entries: array, requirements: array, lockfile_version?: int|string} */
    public function handle(string $file, string $contents): array
    {
        if (! class_exists(Toml::class)) {
            throw new RuntimeException('Parser unavailable');
        }
        if (substr_count($contents, "\n") > 100000 || substr_count($contents, '{') + substr_count($contents, '[') > 100000) {
            throw new RuntimeException('Too many entries');
        }
        try {
            $data = Toml::decode($contents, asArray: true);
        } catch (Throwable) {
            throw new RuntimeException('Malformed file');
        }

        return match ($file) {
            'pyproject.toml' => $this->pythonManifest($data),
            'uv.lock' => $this->uvLock($data),
            'poetry.lock' => $this->poetryLock($data),
            'Cargo.toml' => $this->cargoManifest($data),
            'Cargo.lock' => $this->cargoLock($data),
            default => throw new RuntimeException('Unsupported manifest format'),
        };
    }

    private function pythonManifest(array $data): array
    {
        $project = $this->mapping($data['project'] ?? []);
        $poetry = $this->mapping($data['tool']['poetry'] ?? []);
        if (array_intersect($this->listing($project['dynamic'] ?? []), ['dependencies', 'optional-dependencies'])) {
            throw new RuntimeException('Dynamic dependencies unavailable');
        }
        $entries = [];
        $groups = [['dependencies' => $project['dependencies'] ?? [], 'scope' => 'Production']];
        foreach ($this->mapping($project['optional-dependencies'] ?? []) as $dependencies) {
            $groups[] = ['dependencies' => $dependencies, 'scope' => 'Optional'];
        }
        foreach ($this->mapping($data['dependency-groups'] ?? []) as $dependencies) {
            $groups[] = ['dependencies' => $dependencies, 'scope' => 'Development'];
        }
        $uvSources = [];
        foreach ($this->mapping($data['tool']['uv']['sources'] ?? []) as $name => $source) {
            $uvSources[$this->ecosystems->pythonRequirement($this->text($name))['name']] = $source;
        }
        foreach ($groups as $group) {
            foreach ($this->listing($group['dependencies']) as $declaration) {
                if (is_array($declaration) && isset($declaration['include-group'])) {
                    if (! is_string($declaration['include-group']) || ! isset($data['dependency-groups'][$declaration['include-group']])) {
                        throw new RuntimeException('Malformed file');
                    }

                    continue;
                }
                $entry = $this->ecosystems->pythonRequirement($this->text($declaration), $group['scope']);
                $entry['version'] = null;
                if (isset($uvSources[$entry['name']])) {
                    $entry['link'] = 'Custom package source';
                }
                $this->append($entries, $entry);
            }
        }
        $poetryGroups = [['dependencies' => $poetry['dependencies'] ?? [], 'scope' => 'Production'], ['dependencies' => $poetry['dev-dependencies'] ?? [], 'scope' => 'Development']];
        foreach ($this->mapping($poetry['group'] ?? []) as $group) {
            $poetryGroups[] = ['dependencies' => $this->mapping($group)['dependencies'] ?? [], 'scope' => 'Development'];
        }
        foreach ($poetryGroups as $group) {
            foreach ($this->mapping($group['dependencies']) as $name => $dependency) {
                if ($name === 'python') {
                    continue;
                }
                $variants = is_array($dependency) && array_is_list($dependency) ? $dependency : [$dependency];
                foreach ($variants as $variant) {
                    $attributes = is_string($variant) ? ['version' => $variant] : $this->mapping($variant);
                    $link = $this->sourceLink($attributes, ['git', 'path', 'url', 'source']);
                    $this->append($entries, $this->entry($this->text($name), null, $this->text($attributes['version'] ?? '*'), ! empty($attributes['optional']) ? 'Optional' : $group['scope'], $link));
                }
            }
        }
        $requirements = [];
        if (isset($project['requires-python']) || isset($poetry['dependencies']['python'])) {
            $requirements['python'] = $this->text($project['requires-python'] ?? $poetry['dependencies']['python']);
        }
        if (isset($project['name']) || isset($poetry['name'])) {
            $requirements['project-name'] = $this->text($project['name'] ?? $poetry['name']);
        }

        return ['entries' => $entries, 'requirements' => $requirements];
    }

    private function uvLock(array $data): array
    {
        if (($data['version'] ?? null) !== 1 || ! in_array($data['revision'] ?? 0, [0, 1, 2, 3], true)) {
            throw new RuntimeException('Unsupported lockfile version');
        }
        $entries = [];
        foreach ($this->listing($data['package'] ?? null) as $package) {
            $package = $this->mapping($package);
            $source = $this->mapping($package['source'] ?? null);
            if (! $source || (! isset($package['version']) && ! isset($source['virtual']) && ! isset($source['editable']))) {
                throw new RuntimeException('Malformed file');
            }
            $registry = $source['registry'] ?? null;
            $public = is_string($registry) && in_array(rtrim($registry, '/'), ['https://pypi.org/simple', 'https://pypi.python.org/simple'], true);
            $link = $public ? null : $this->sourceLink($source, ['editable', 'virtual', 'directory', 'path', 'git', 'url', 'registry']) ?? 'Unknown package source';
            $entry = $this->entry($this->text($package['name'] ?? null), isset($package['version']) ? $this->text($package['version']) : null, null, 'Unknown', $link);
            $entry['root'] = ($source['editable'] ?? $source['virtual'] ?? null) === '.';
            $this->append($entries, $entry);
        }

        return ['entries' => $entries, 'requirements' => isset($data['requires-python']) ? ['python' => $this->text($data['requires-python'])] : [], 'lockfile_version' => 1];
    }

    private function poetryLock(array $data): array
    {
        $metadata = $this->mapping($data['metadata'] ?? null);
        if (! in_array($metadata['lock-version'] ?? null, ['1.0', '1.1', '2.0', '2.1'], true)) {
            throw new RuntimeException('Unsupported lockfile version');
        }
        $entries = [];
        foreach ($this->listing($data['package'] ?? null) as $package) {
            $package = $this->mapping($package);
            $source = $this->mapping($package['source'] ?? []);
            $groups = $this->listing($package['groups'] ?? []);
            $scope = ($package['category'] ?? null) === 'dev' || ($groups && ! in_array('main', $groups, true)) ? 'Development' : 'Production';
            $url = $source['url'] ?? null;
            $public = ! $source || (($source['type'] ?? null) === 'legacy' && is_string($url) && in_array(rtrim($url, '/'), ['https://pypi.org/simple', 'https://pypi.python.org/simple'], true));
            $link = $public ? null : $this->sourceLink($source, ['url', 'reference', 'type']) ?? 'Custom package source';
            $this->append($entries, $this->entry($this->text($package['name'] ?? null), $this->text($package['version'] ?? null), null, ! empty($package['optional']) ? 'Optional' : $scope, $link));
        }

        return ['entries' => $entries, 'requirements' => isset($metadata['python-versions']) ? ['python' => $this->text($metadata['python-versions'])] : [], 'lockfile_version' => $metadata['lock-version']];
    }

    private function cargoManifest(array $data): array
    {
        $entries = [];
        $tables = [$data];
        foreach ($this->mapping($data['target'] ?? []) as $target) {
            $tables[] = $this->mapping($target);
        }
        foreach ($tables as $table) {
            foreach (['dependencies' => 'Production', 'dev-dependencies' => 'Development', 'build-dependencies' => 'Development'] as $key => $scope) {
                foreach ($this->mapping($table[$key] ?? []) as $name => $dependency) {
                    $attributes = is_string($dependency) ? ['version' => $dependency] : $this->mapping($dependency);
                    if (($attributes['workspace'] ?? false) === true && isset($data['workspace']['dependencies'][$name])) {
                        $inherited = $data['workspace']['dependencies'][$name];
                        $attributes = [...$attributes, ...(is_string($inherited) ? ['version' => $inherited] : $this->mapping($inherited))];
                        unset($attributes['workspace']);
                    }
                    $link = $this->sourceLink($attributes, ['git', 'path', 'registry', 'registry-index', 'workspace']);
                    $this->append($entries, $this->entry($this->text($attributes['package'] ?? $name), null, $this->text($attributes['version'] ?? '*'), ! empty($attributes['optional']) ? 'Optional' : $scope, $link));
                }
            }
        }
        $requirements = [];
        if (isset($data['package']['rust-version']) && is_string($data['package']['rust-version'])) {
            $requirements['rust'] = $this->text($data['package']['rust-version']);
        }
        if (isset($data['package']['name'])) {
            $requirements['project-name'] = $this->text($data['package']['name']);
        }

        return ['entries' => $entries, 'requirements' => $requirements];
    }

    private function cargoLock(array $data): array
    {
        $version = $data['version'] ?? 1;
        if (! in_array($version, [1, 2, 3, 4], true)) {
            throw new RuntimeException('Unsupported lockfile version');
        }
        $entries = [];
        foreach ($this->listing($data['package'] ?? null) as $package) {
            $package = $this->mapping($package);
            $source = isset($package['source']) ? $this->text($package['source']) : null;
            $public = in_array($source, ['registry+https://github.com/rust-lang/crates.io-index', 'sparse+https://index.crates.io/'], true);
            $this->append($entries, $this->entry($this->text($package['name'] ?? null), $this->text($package['version'] ?? null), null, 'Unknown', $public ? null : $source ?? 'Local package'));
        }

        return ['entries' => $entries, 'requirements' => [], 'lockfile_version' => $version];
    }

    private function entry(string $name, ?string $version, ?string $required, string $scope, ?string $link): array
    {
        if (strlen($name) > 255) {
            throw new RuntimeException('Malformed file');
        }

        return ['name' => $name, 'version' => $version, 'required' => $required, 'scope' => $scope, 'location' => null, 'link' => $link];
    }

    private function sourceLink(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($data[$key])) {
                return $key.':'.(is_bool($data[$key]) ? ($data[$key] ? 'true' : 'false') : $this->text($data[$key]));
            }
        }

        return null;
    }

    private function append(array &$entries, array $entry): void
    {
        $entries[] = $entry;
        if (count($entries) > ReadDependencies::ENTRY_LIMIT) {
            throw new RuntimeException('Too many entries');
        }
    }

    private function text(mixed $value): string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 4096 || ! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x1F]/', $value)) {
            throw new RuntimeException('Malformed file');
        }

        return $value;
    }

    private function mapping(mixed $value): array
    {
        if (! is_array($value) || ($value && array_is_list($value))) {
            throw new RuntimeException('Malformed file');
        }

        return $value;
    }

    private function listing(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException('Malformed file');
        }

        return $value;
    }
}
