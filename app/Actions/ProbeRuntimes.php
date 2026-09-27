<?php

namespace App\Actions;

use App\Models\ProjectFolder;
use App\WorkspacePreferences;
use Illuminate\Validation\ValidationException;

class ProbeRuntimes
{
    public const TOOLS = ['php', 'node', 'composer', 'npm', 'pnpm', 'yarn'];

    public function handle(string $root, array $overrides = [], array $previous = [], ?array $globalPaths = null): array
    {
        $globalPaths ??= app(WorkspacePreferences::class)->get('tools.paths');
        $excludedRoots = $this->excludedRoots($root);
        $paths = [];
        foreach (self::TOOLS as $tool) {
            $override = ($overrides[$tool] ?? null) ?: ($globalPaths[$tool] ?? null);
            $paths[$tool] = [...$this->resolve($tool, $excludedRoots, $override), 'source' => ! empty($overrides[$tool]) ? 'root' : ($override ? 'global' : 'automatic')];
        }
        $results = [];
        foreach ($paths as $tool => $candidate) {
            $result = [...$candidate, 'tool' => $tool, 'version' => null, 'probe_directory' => '/', 'scanned_at' => null];
            $command = null;
            if ($candidate['state'] === 'Current') {
                $path = $candidate['path'];
                if (! $this->supported($tool, $path)) {
                    $result['state'] = 'Unsupported wrapper; select the underlying executable';
                } elseif (in_array($tool, ['php', 'node'], true)) {
                    $command = $tool === 'php' ? [$path, '-n', '-v'] : [$path, '--version'];
                } elseif ($tool === 'composer' && $paths['php']['state'] === 'Current' && $this->binary($paths['php']['path'])) {
                    $command = [$paths['php']['path'], '-n', $path, '--no-plugins', '--no-scripts', '--no-interaction', '--no-ansi', '--version'];
                } elseif (in_array($tool, ['npm', 'pnpm', 'yarn'], true) && $paths['node']['state'] === 'Current' && $this->binary($paths['node']['path'])) {
                    $command = [$paths['node']['path'], $path, '--version'];
                } else {
                    $result['state'] = $tool === 'composer' ? 'PHP executable unavailable' : 'Node executable unavailable';
                }
            }
            if ($command) {
                // Probe outside the project so package-manager configuration and project hooks are not loaded.
                $probe = app(RunInspectionProcess::class)->handle($command, '/', [
                    'PATH' => implode(':', array_unique(array_map('dirname', array_filter(array_column($paths, 'path'))))).':/usr/bin:/bin',
                    'COMPOSER_DISABLE_NETWORK' => '1', 'COMPOSER_HOME' => '/dev/null',
                    'COREPACK_ENABLE_NETWORK' => '0', 'COREPACK_ENABLE_PROJECT_SPEC' => '0',
                    'YARN_IGNORE_PATH' => '1', 'YARN_ENABLE_NETWORK' => '0', 'npm_config_ignore_scripts' => 'true',
                    'npm_config_userconfig' => '/dev/null', 'npm_config_globalconfig' => '/dev/null/.npmrc',
                    'npm_config_update_notifier' => 'false', 'NO_COLOR' => '1', 'FORCE_COLOR' => '0', 'SHELL_VERBOSITY' => '0',
                ], includeStderr: true);
                $pattern = match ($tool) {
                    'php' => '/^PHP ([0-9][0-9A-Za-z.+-]*)\b/m',
                    'composer' => '/^Composer (?:version )?([0-9][0-9A-Za-z.+-]*)\b/m',
                    default => '/^v?([0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.+-]+)?)\r?$/m',
                };
                if ($probe['state'] === 'Current' && preg_match($pattern, $probe['output'], $match)) {
                    $result['version'] = $match[1];
                    $result['scanned_at'] = now()->toIso8601String();
                } else {
                    $result['state'] = $probe['state'] === 'Current' ? 'Unrecognized version output' : $probe['state'];
                }
            }
            if (! $result['version'] && isset($previous[$tool])) {
                $last = $previous[$tool];
                $result['last_success'] = ! empty($last['version']) ? array_intersect_key($last, array_flip(['version', 'path', 'probe_directory', 'scanned_at'])) : ($last['last_success'] ?? null);
            }
            $results[$tool] = $result;
        }

        return $results;
    }

    public function validatePaths(array $paths, string $prefix = 'paths'): void
    {
        $excludedRoots = $this->excludedRoots('');
        $errors = [];
        foreach ($paths as $tool => $path) {
            if ($path === null && in_array($tool, self::TOOLS, true)) {
                continue;
            }
            if (! in_array($tool, self::TOOLS, true) || ! is_string($path) || ! str_starts_with($path, '/') || str_contains($path, "\0") || strlen($path) > 4096) {
                $errors[$prefix.'.'.$tool] = 'Choose an absolute executable path or automatic detection.';

                continue;
            }
            $resolved = $this->resolve($tool, $excludedRoots, $path);
            if ($resolved['state'] !== 'Current') {
                $errors[$prefix.'.'.$tool] = $resolved['state'];
            } elseif (! $this->supported($tool, $resolved['path'])) {
                $errors[$prefix.'.'.$tool] = 'Unsupported wrapper; select the underlying executable';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function excludedRoots(string $root): array
    {
        return array_filter([$root, ...ProjectFolder::pluck('path')->map(fn (string $path): string => realpath($path) ?: $path)->all()]);
    }

    private function resolve(string $tool, array $excludedRoots, ?string $override): array
    {
        $candidates = $override ? [$override] : array_map(fn ($directory) => $directory.'/'.$tool, array_filter(explode(PATH_SEPARATOR, getenv('PATH') ?: ''), fn ($directory) => str_starts_with($directory, '/')));
        $rejected = null;
        foreach ($candidates as $candidate) {
            $path = realpath($candidate);
            if (! $path || ! is_file($path) || ! is_executable($path)) {
                continue;
            }
            if (array_any($excludedRoots, fn (string $root): bool => $path === $root || str_starts_with($path, rtrim($root, '/').'/'))) {
                $rejected = 'Project executables are not probed';

                continue;
            }
            $bundle = preg_match('~^(.+\.app)/Contents/~', PHP_BINARY, $match) ? $match[1].'/' : null;
            if (str_contains($path, '/vendor/nativephp/') || str_contains($path, '/Electron.app/') || ($bundle && str_starts_with($path, $bundle))) {
                $rejected = 'Orbit bundled executable excluded';

                continue;
            }

            return ['path' => $path, 'state' => 'Current'];
        }

        return ['path' => $override, 'state' => $rejected ?? 'Missing executable'];
    }

    private function supported(string $tool, string $path): bool
    {
        return match ($tool) {
            'php', 'node' => $this->binary($path),
            'composer' => $this->composer($path),
            default => $this->nodeCli($tool, $path),
        };
    }

    private function binary(string $path): bool
    {
        $header = @file_get_contents($path, false, null, 0, 4);

        return in_array(bin2hex($header ?: ''), ['cffaedfe', 'cefaedfe', 'feedfacf', 'feedface', 'cafebabe', 'bebafeca', 'cafebabf', 'bfbafeca', '7f454c46'], true);
    }

    private function composer(string $path): bool
    {
        $header = @file_get_contents($path, false, null, 0, 65536) ?: '';

        return str_contains($header, 'Phar::mapPhar(') && str_contains($header, '__HALT_COMPILER();');
    }

    private function nodeCli(string $tool, string $path): bool
    {
        $suffix = match ($tool) {
            'npm' => '/npm/bin/npm-cli.js', 'pnpm' => '/pnpm/bin/pnpm.cjs', 'yarn' => '/yarn/bin/yarn.js',
        };
        if (! str_ends_with($path, $suffix)) {
            return false;
        }
        try {
            $package = app(ReadDependencies::class)->readJson(dirname($path, 2), 'package.json');

            return ($package->name ?? null) === $tool;
        } catch (\Throwable) {
            return false;
        }
    }
}
