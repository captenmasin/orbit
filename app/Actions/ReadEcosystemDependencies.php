<?php

namespace App\Actions;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use stdClass;
use Symfony\Component\Yaml\Yaml;
use Throwable;

class ReadEcosystemDependencies
{
    public function handle(string $file, string $contents): array
    {
        if (substr_count($contents, "\n") > 100000) {
            throw new RuntimeException('Too many entries');
        }
        if (str_contains($contents, "\0")) {
            throw new RuntimeException('Malformed file');
        }

        return match (basename($file)) {
            'requirements.txt' => $this->requirements($contents),
            'go.mod' => $this->goMod($contents),
            'Gemfile.lock' => $this->gemfileLock($contents),
            'packages.lock.json' => $this->nugetLock($contents),
            'pubspec.lock' => $this->pubspecLock($contents),
            'pom.xml' => $this->pom($contents),
            default => str_ends_with($file, '.lockfile') ? $this->gradleLock($file, $contents) : throw new RuntimeException('Unsupported lockfile format'),
        };
    }

    public function pythonRequirement(string $declaration, string $scope = 'Production'): array
    {
        $declaration = $this->text(trim($declaration));
        $requirement = trim(explode(';', $declaration, 2)[0]);
        if (! preg_match('/^([A-Za-z0-9](?:[A-Za-z0-9._-]*[A-Za-z0-9])?)(?:\[[A-Za-z0-9., _-]+\])?\s*(.*)$/', $requirement, $match)) {
            return $this->entry($declaration, null, $declaration, $scope, 'Direct', null, $declaration);
        }
        $name = preg_replace('/[-_.]+/', '-', strtolower($match[1]));
        $constraint = trim($match[2]);
        $version = preg_match('/^={2,3}\s*([0-9][A-Za-z0-9.!+_-]*)$/', trim($constraint, '() '), $pin) ? $pin[1] : null;
        $link = str_starts_with($constraint, '@') ? trim(substr($constraint, 1)) : null;
        if ($constraint !== '' && ! preg_match('/^(?:@|[<>=!~(])/', $constraint)) {
            $link = $declaration;
        }

        return $this->entry($name, $link ? null : $version, $declaration, $scope, 'Direct', null, $link);
    }

    private function requirements(string $contents): array
    {
        $lines = explode("\n", preg_replace('/\\\\\r?\n/', '', $contents));
        $entries = [];
        $source = null;
        foreach ($lines as $line) {
            $line = trim(preg_replace('/(?:^|\s+)#.*$/', '', $line));
            if ($line === '') {
                continue;
            }
            if (preg_match('/^(?:-i\s*|--(?:extra-)?index-url[=\s]+|--find-links[=\s]+|-f\s*)(.+)$/', $line, $match)) {
                if (! in_array(rtrim(trim($match[1]), '/'), ['https://pypi.org/simple', 'https://pypi.python.org/simple'], true) || str_starts_with($line, '--find-links') || str_starts_with($line, '-f')) {
                    $source = $this->text($line);
                }

                continue;
            }
            if ($line === '--no-index') {
                $source = $line;

                continue;
            }
            if (preg_match('/^--(?:require-hashes|pre|prefer-binary|only-binary|no-binary|trusted-host)(?:[=\s].*)?$/', $line)) {
                continue;
            }
            $line = trim(preg_replace('/\s+--hash(?:=|\s+)\S+/', '', $line));
            $entries[] = $this->pythonRequirement($line);
            $this->limit($entries);
        }
        if ($source !== null) {
            foreach ($entries as &$entry) {
                $entry['version'] = null;
                $entry['link'] ??= $source;
            }
            unset($entry);
        }

        return ['entries' => $entries, 'requirements' => []];
    }

    private function goMod(string $contents): array
    {
        $entries = $requirements = $replacements = $excluded = [];
        $block = null;
        $module = false;
        foreach (explode("\n", $contents) as $line) {
            $indirect = preg_match('~//\s*indirect\s*$~', $line);
            $line = trim(preg_replace('~"(?:[^"\\\\]|\\\\.)*"(*SKIP)(*F)|`[^`]*`(*SKIP)(*F)|//.*$~', '', $line));
            if ($line === '') {
                continue;
            }
            if ($line === ')') {
                if ($block === null) {
                    throw new RuntimeException('Malformed file');
                }
                $block = null;

                continue;
            }
            $directive = $block;
            if ($directive === null) {
                if (! preg_match('/^(module|go|toolchain|require|replace|exclude|retract|tool|godebug)\s+(.+)$/', $line, $match)) {
                    throw new RuntimeException('Unsupported lockfile format');
                }
                [$directive, $line] = [$match[1], trim($match[2])];
                if ($line === '(') {
                    $block = $directive;

                    continue;
                }
            }
            preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|`[^`]*`|=>|[^\s]+/', $line, $matches);
            $tokens = array_map(fn (string $token): string => trim($token, '"`'), $matches[0]);
            if ($directive === 'module') {
                $module = count($tokens) === 1;
            } elseif (in_array($directive, ['go', 'toolchain'], true)) {
                $requirements[$directive] = $this->text($line);
            } elseif (in_array($directive, ['require', 'exclude'], true)) {
                if (count($tokens) !== 2 || ! $this->goVersion($tokens[1])) {
                    throw new RuntimeException('Malformed file');
                }
                if ($directive === 'exclude') {
                    $excluded[$tokens[0].'@'.$tokens[1]] = true;
                } else {
                    $entries[] = $this->entry($tokens[0], $tokens[1], $tokens[1], 'Unknown', $indirect ? 'Transitive' : 'Direct');
                    $this->limit($entries);
                }
            } elseif ($directive === 'replace') {
                $arrow = array_search('=>', $tokens, true);
                if (! in_array($arrow, [1, 2], true) || ! in_array(count($tokens) - $arrow - 1, [1, 2], true)) {
                    throw new RuntimeException('Malformed file');
                }
                $replacements[$tokens[0].($arrow === 2 ? '@'.$tokens[1] : '')] = $this->text(implode(' ', array_slice($tokens, $arrow + 1)));
            }
        }
        if (! $module || $block !== null) {
            throw new RuntimeException('Malformed file');
        }
        foreach ($entries as &$entry) {
            $replacement = $replacements[$entry['name'].'@'.$entry['version']] ?? $replacements[$entry['name']] ?? null;
            if ($replacement !== null || isset($excluded[$entry['name'].'@'.$entry['version']])) {
                $entry['version'] = null;
                $entry['link'] = $replacement ?? 'Excluded version';
            }
        }
        unset($entry);

        return ['entries' => $entries, 'requirements' => $requirements];
    }

    private function goVersion(string $version): bool
    {
        return (bool) preg_match('/^v\d+\.\d+\.\d+(?:-[A-Za-z0-9.-]+)?(?:\+[A-Za-z0-9.-]+)?$/', $version);
    }

    private function gemfileLock(string $contents): array
    {
        $entries = $requirements = $direct = [];
        $section = $remote = null;
        $specs = $gem = false;
        $publicSource = true;
        foreach (explode("\n", $contents) as $line) {
            if (preg_match('/^([A-Z][A-Z ]+)$/', rtrim($line), $match)) {
                $section = $match[1];
                $remote = null;
                $specs = false;
                $publicSource = true;
                $gem = $gem || in_array($section, ['GEM', 'GIT', 'PATH'], true);
            } elseif (in_array($section, ['GEM', 'GIT', 'PATH'], true)) {
                if (preg_match('/^  remote: (.+)$/', $line, $match)) {
                    $remote = $this->text(($remote === null ? '' : $remote.', ').$match[1]);
                    $publicSource = $publicSource && in_array(rtrim($match[1], '/'), ['https://rubygems.org', 'http://rubygems.org'], true);
                } elseif (rtrim($line) === '  specs:') {
                    $specs = true;
                } elseif ($specs && preg_match('/^    ([A-Za-z0-9_.-]+) \(([^()]+)\)\s*$/', $line, $match)) {
                    if (! preg_match('/^([0-9][A-Za-z0-9.]*)(?:-(.+))?$/', $match[2], $version)) {
                        throw new RuntimeException('Malformed file');
                    }
                    $link = $section === 'GEM' && $publicSource && $remote !== null ? null : ($remote ?? $section);
                    $entries[] = $this->entry($match[1], $link === null ? $version[1] : null, $match[2], 'Unknown', 'Transitive', $version[2] ?? null, $link);
                    $this->limit($entries);
                } elseif ($specs && preg_match('/^    \S/', $line)) {
                    throw new RuntimeException('Malformed file');
                }
            } elseif ($section === 'DEPENDENCIES' && preg_match('/^  ([A-Za-z0-9_.-]+)(?:!|\s|$)/', $line, $match)) {
                $direct[$match[1]] = true;
            } elseif ($section === 'RUBY VERSION' && trim($line) !== '') {
                $requirements['ruby'] = $this->text(trim($line));
            }
        }
        if (! $gem) {
            throw new RuntimeException('Malformed file');
        }
        foreach ($entries as &$entry) {
            $entry['identity'] = isset($direct[$entry['name']]) ? 'Direct' : 'Transitive';
        }
        unset($entry);

        return ['entries' => $entries, 'requirements' => $requirements];
    }

    private function nugetLock(string $contents): array
    {
        $data = $this->json($contents);
        if (! in_array($data->version ?? null, [1, 2], true)) {
            throw new RuntimeException('Unsupported lockfile version');
        }
        if (! ($data->dependencies ?? null) instanceof stdClass) {
            throw new RuntimeException('Malformed file');
        }
        $entries = [];
        foreach ($data->dependencies as $framework => $packages) {
            if (! $packages instanceof stdClass) {
                throw new RuntimeException('Malformed file');
            }
            foreach ($packages as $name => $package) {
                if (! $package instanceof stdClass || ! in_array($package->type ?? null, ['Direct', 'Transitive', 'Project'], true)) {
                    throw new RuntimeException('Malformed file');
                }
                $project = $package->type === 'Project';
                $version = $project ? null : $this->text($package->resolved ?? null);
                if (! $project && $version === null) {
                    throw new RuntimeException('Malformed file');
                }
                $entries[] = $this->entry($name, $version, $this->text($package->requested ?? null), 'Unknown', $project ? 'Direct' : $package->type, $framework, $project ? 'Project reference' : null);
                $this->limit($entries);
            }
        }

        return ['entries' => $entries, 'requirements' => [], 'lockfile_version' => $data->version];
    }

    private function pubspecLock(string $contents): array
    {
        try {
            $data = Yaml::parse($contents, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE | Yaml::PARSE_EXCEPTION_ON_ALIAS, 64);
        } catch (Throwable) {
            throw new RuntimeException('Malformed file');
        }
        if (! is_array($data) || ! is_array($data['packages'] ?? null)) {
            throw new RuntimeException('Malformed file');
        }
        $entries = [];
        foreach ($data['packages'] as $name => $package) {
            $name = $this->text($name, 255);
            if (! is_array($package) || ! is_string($package['source'] ?? null)) {
                throw new RuntimeException('Malformed file');
            }
            $description = $package['description'] ?? null;
            $url = is_array($description) ? ($description['url'] ?? $description['path'] ?? null) : $description;
            $hosted = $package['source'] === 'hosted' && is_string($url) && in_array(rtrim($url, '/'), ['https://pub.dev', 'https://pub.dartlang.org'], true);
            if ($hosted && is_array($description) && ($description['name'] ?? $name) !== $name) {
                throw new RuntimeException('Malformed file');
            }
            $dependency = $this->text($package['dependency'] ?? null);
            $scope = match ($dependency) {
                'direct main' => 'Production', 'direct dev' => 'Development', default => 'Unknown'
            };
            $identity = match ($dependency) {
                'direct main', 'direct dev' => 'Direct', 'transitive' => 'Transitive', default => 'Lock'
            };
            $version = $this->text($package['version'] ?? null);
            if ($version === null) {
                throw new RuntimeException('Malformed file');
            }
            $entries[] = $this->entry($name, $hosted ? $version : null, null, $scope, $identity, null, $hosted ? null : ($this->text($url) ?? $package['source']));
            $this->limit($entries);
        }
        $requirements = [];
        if (isset($data['sdks']) && ! is_array($data['sdks'])) {
            throw new RuntimeException('Malformed file');
        }
        foreach ($data['sdks'] ?? [] as $name => $version) {
            $requirements[$this->text($name, 255)] = $this->text($version);
        }

        return ['entries' => $entries, 'requirements' => $requirements];
    }

    private function pom(string $contents): array
    {
        if (preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $contents) || substr_count($contents, '<') > 100000) {
            throw new RuntimeException('Malformed file');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            if (! $document->loadXML($contents, LIBXML_NONET | LIBXML_COMPACT) || $document->documentElement?->localName !== 'project') {
                throw new RuntimeException('Malformed file');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($document);
        if ($xpath->query('//*[count(ancestor::*) > 64]')->length > 0) {
            throw new RuntimeException('Malformed file');
        }
        $project = $document->documentElement;
        $properties = [];
        foreach ($xpath->query('./*[local-name()="properties"]/*', $project) as $property) {
            $properties[$property->localName] = $this->text(trim($property->textContent));
        }
        foreach (['groupId', 'artifactId', 'version'] as $name) {
            $properties['project.'.$name] = $this->xmlText($xpath, $project, $name) ?? $this->xmlText($xpath, $project, 'parent/'.$name);
            $properties['pom.'.$name] = $properties['project.'.$name];
            $properties['project.parent.'.$name] = $this->xmlText($xpath, $project, 'parent/'.$name);
        }
        $repository = null;
        foreach ($xpath->query('.//*[local-name()="repositories"]/*[local-name()="repository"]/*[local-name()="url"]', $project) as $url) {
            $value = $this->expand($this->text(trim($url->textContent)), $properties);
            if (! in_array(rtrim($value, '/'), ['https://repo.maven.apache.org/maven2', 'https://repo1.maven.org/maven2', 'http://repo.maven.apache.org/maven2', 'http://repo1.maven.org/maven2'], true)) {
                $repository = $value === '' ? 'Unknown Maven repository' : $value;
            }
        }
        $managed = $imports = [];
        foreach ($xpath->query('./*[local-name()="dependencyManagement"]/*[local-name()="dependencies"]/*[local-name()="dependency"]', $project) as $dependency) {
            $key = $this->expand(implode(':', array_map(fn (string $field): string => $this->xmlText($xpath, $dependency, $field) ?? ($field === 'type' ? 'jar' : ''), ['groupId', 'artifactId', 'type', 'classifier'])), $properties);
            if ($this->xmlText($xpath, $dependency, 'scope') === 'import') {
                $name = $this->expand(($this->xmlText($xpath, $dependency, 'groupId') ?? '').':'.($this->xmlText($xpath, $dependency, 'artifactId') ?? ''), $properties);
                $imports[] = $this->entry($name, null, $this->expand($this->xmlText($xpath, $dependency, 'version') ?? '', $properties), 'Development', 'Direct', null, 'Imported BOM');
                $this->limit($imports);
            } else {
                $managed[$key] = $this->xmlText($xpath, $dependency, 'version');
            }
        }
        $entries = [];
        $dependencies = $xpath->query('./*[local-name()="dependencies"]/*[local-name()="dependency"] | ./*[local-name()="profiles"]/*[local-name()="profile"]/*[local-name()="dependencies"]/*[local-name()="dependency"]', $project);
        foreach ($dependencies as $dependency) {
            $group = $this->expand($this->xmlText($xpath, $dependency, 'groupId') ?? '', $properties);
            $artifact = $this->expand($this->xmlText($xpath, $dependency, 'artifactId') ?? '', $properties);
            if ($group === '' || $artifact === '') {
                throw new RuntimeException('Malformed file');
            }
            $name = $group.':'.$artifact;
            $key = $name.':'.$this->expand($this->xmlText($xpath, $dependency, 'type') ?? 'jar', $properties).':'.$this->expand($this->xmlText($xpath, $dependency, 'classifier') ?? '', $properties);
            $required = $this->expand($this->xmlText($xpath, $dependency, 'version') ?? $managed[$key] ?? '', $properties);
            $profile = $dependency->parentNode->parentNode->localName === 'profile';
            $link = $profile ? 'Profile activation unknown' : $repository;
            $systemPath = $this->xmlText($xpath, $dependency, 'systemPath');
            if ($systemPath !== null) {
                $link = $this->expand($systemPath, $properties);
            }
            $scope = match ($this->xmlText($xpath, $dependency, 'scope')) {
                'test' => 'Development', 'provided', 'system' => 'Unknown', default => 'Production'
            };
            if ($this->xmlText($xpath, $dependency, 'optional') === 'true') {
                $scope = 'Optional';
            }
            $exact = $required !== '' && ! preg_match('/[\s${}\[\](),+*]|^(?:LATEST|RELEASE)$|-SNAPSHOT$/i', $required) && ! str_contains($name, '${');
            $entries[] = $this->entry($name, $exact && $link === null ? $required : null, $required ?: null, $scope, 'Direct', $profile ? 'profile' : null, $link);
            $this->limit($entries);
        }
        array_push($entries, ...$imports);
        $this->limit($entries);
        $parent = $xpath->query('./*[local-name()="parent"]', $project)->item(0);
        if ($parent instanceof DOMElement) {
            $group = $this->xmlText($xpath, $parent, 'groupId');
            $artifact = $this->xmlText($xpath, $parent, 'artifactId');
            if (! $group || ! $artifact) {
                throw new RuntimeException('Malformed file');
            }
            $name = $group.':'.$artifact;
            $entries[] = $this->entry($name, null, $this->xmlText($xpath, $parent, 'version'), 'Development', 'Direct', null, 'Parent POM');
            $this->limit($entries);
        }

        return ['entries' => $entries, 'requirements' => []];
    }

    private function xmlText(DOMXPath $xpath, DOMElement $element, string $path): ?string
    {
        $query = './'.implode('/', array_map(fn (string $name): string => '*[local-name()="'.$name.'"]', explode('/', $path)));
        $node = $xpath->query($query, $element)->item(0);

        return $node ? $this->text(trim($node->textContent)) : null;
    }

    private function expand(string $value, array $properties): string
    {
        for ($attempt = 0; $attempt < 10 && str_contains($value, '${'); $attempt++) {
            $expanded = preg_replace_callback('/\$\{([^}]+)\}/', fn (array $match): string => $properties[$match[1]] ?? $match[0], $value);
            $this->text($expanded);
            if ($expanded === $value) {
                break;
            }
            $value = $expanded;
        }

        return $value;
    }

    private function gradleLock(string $file, string $contents): array
    {
        $entries = [];
        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, 'empty=')) {
                continue;
            }
            if (! preg_match('/^([^\s:=]+):([^\s:=]+):([^\s:=]+)(?:=(.*))?$/', $line, $match)) {
                throw new RuntimeException('Malformed file');
            }
            $configuration = $match[4] ?? basename($file, '.lockfile');
            $development = basename($file) === 'buildscript-gradle.lockfile' || ! preg_match('/(?:^|,)\s*(?![^,]*test)[^,]+/i', $configuration);
            $version = preg_match('/[${}\[\](),+*]|^(?:LATEST|RELEASE)$|-SNAPSHOT$/i', $match[3]) ? null : $match[3];
            $entries[] = $this->entry($match[1].':'.$match[2], $version, $match[3], $development ? 'Development' : 'Unknown', 'Lock', $configuration);
            $this->limit($entries);
        }

        return ['entries' => $entries, 'requirements' => []];
    }

    private function json(string $contents): stdClass
    {
        if (substr_count($contents, '{') + substr_count($contents, '[') > 100000) {
            throw new RuntimeException('Too many entries');
        }
        try {
            $data = json_decode($contents, false, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('Malformed file');
        }
        if (! $data instanceof stdClass) {
            throw new RuntimeException('Malformed file');
        }

        return $data;
    }

    /** @return array{name: string, version: ?string, required: ?string, scope: string, identity: string, location: ?string, link: ?string} */
    private function entry(string $name, ?string $version, ?string $required, string $scope, string $identity, ?string $location = null, ?string $link = null): array
    {
        return ['name' => $this->text($name, 255), 'version' => $this->text($version), 'required' => $this->text($required), 'scope' => $scope, 'identity' => $identity, 'location' => $this->text($location), 'link' => $this->text($link)];
    }

    private function text(mixed $value, int $limit = 1024): ?string
    {
        if ($value !== null && (! is_string($value) || strlen($value) > $limit || ! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x1F]/', $value))) {
            throw new RuntimeException('Malformed file');
        }

        return $value;
    }

    private function limit(array $entries): void
    {
        if (count($entries) > ReadDependencies::ENTRY_LIMIT) {
            throw new RuntimeException('Too many entries');
        }
    }
}
