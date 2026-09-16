<?php

namespace App\Actions;

use App\Rules\ProjectUrl;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

class InspectFolder
{
    public function handle(string $path): array
    {
        if (! str_starts_with($path, '/') || str_contains($path, "\0") || ! is_dir($path) || ! is_readable($path)) {
            throw ValidationException::withMessages(['path' => 'Choose an existing folder you can read.']);
        }
        $path = realpath($path);
        $metadata = ['path' => $path, 'name' => basename($path), 'description' => null, 'remote_url' => null, 'warnings' => []];
        foreach (['composer.json', 'package.json'] as $manifest) {
            try {
                $data = app(ReadDependencies::class)->readJson($path, $manifest);
                if (is_string($data->name ?? null) && trim($data->name) !== '') {
                    $metadata['name'] = mb_substr(trim($data->name), 0, 255);
                }
                if (is_string($data->description ?? null)) {
                    $metadata['description'] = mb_substr($data->description, 0, 10000);
                }
            } catch (RuntimeException $exception) {
                if ($exception->getMessage() !== 'Missing file') {
                    $metadata['warnings'][] = $exception->getMessage() === 'Malformed file' ? $manifest.' contains invalid JSON.' : $manifest.' could not be read (maximum 256 KB).';
                }
            }
        }
        $git = $this->gitMetadata($path);
        $metadata['remote_url'] = $git['git_remote'];
        if ($git['scan_error']) {
            $metadata['warnings'][] = $git['scan_error'];
        }

        return [...$metadata, ...$git];
    }

    public function gitMetadata(string $path): array
    {
        $metadata = [
            'git_state' => 'Not a Git repository', 'branch' => null, 'last_commit_hash' => null,
            'last_commit_at' => null, 'scanned_at' => now()->toIso8601String(), 'git_root' => null,
            'git_remote' => null, 'commit_subject' => null, 'scan_error' => null,
        ];
        if (! is_dir($path) || ! is_readable($path)) {
            return [...$metadata, 'scan_error' => is_dir($path) ? 'Permission denied' : 'Missing folder'];
        }
        $directory = $this->git($path, ['rev-parse', '--absolute-git-dir']);
        if ($directory['state'] !== 'Current') {
            if ($directory['state'] !== 'Command failed') {
                return [...$metadata, 'git_state' => 'Git unavailable', 'scan_error' => 'Git '.$directory['state']];
            }
            if ($this->git($path, ['--version'])['state'] !== 'Current') {
                return [...$metadata, 'git_state' => 'Git unavailable', 'scan_error' => 'Git unavailable'];
            }
            for ($parent = $path; $parent !== dirname($parent); $parent = dirname($parent)) {
                if (file_exists($parent.'/.git') || (is_file($parent.'/HEAD') && is_dir($parent.'/objects'))) {
                    return [...$metadata, 'git_state' => 'Git metadata unavailable', 'scan_error' => 'Git metadata unavailable'];
                }
            }

            return $metadata;
        }
        try {
            $bare = $this->git($path, ['rev-parse', '--is-bare-repository']);
            $root = $bare['output'] === 'true' ? $directory : $this->git($path, ['rev-parse', '--show-toplevel']);
            $remote = $this->git($path, ['config', '--local', '--no-includes', '--get', 'remote.origin.url']);
            $branch = $this->git($path, ['symbolic-ref', '--quiet', '--short', 'HEAD']);
            $commit = $this->git($path, ['log', '-1', '--format=%H%x00%cI%x00%s', '--no-show-signature', 'HEAD', '--']);
            foreach ([$bare, $root, $remote, $branch, $commit] as $result) {
                if (! in_array($result['state'], ['Current', 'Command failed'], true)) {
                    throw new RuntimeException('Git '.$result['state']);
                }
            }
            if ($root['state'] !== 'Current') {
                throw new RuntimeException('Git metadata unavailable');
            }
            $metadata['git_root'] = $root['output'];
            $metadata['branch'] = $branch['state'] === 'Current' ? mb_substr($branch['output'], 0, 255) : null;
            if ($remote['output'] && Validator::make(['url' => $remote['output']], ['url' => [new ProjectUrl(repository: true)]])->passes()) {
                $metadata['git_remote'] = $remote['output'];
            }
            if ($commit['state'] === 'Current') {
                $parts = explode("\0", $commit['output'], 3);
                if (count($parts) !== 3 || ! preg_match('/\A[0-9a-f]{40,64}\z/', $parts[0])) {
                    throw new RuntimeException('Git metadata unavailable');
                }
                $metadata['last_commit_hash'] = $parts[0];
                $metadata['last_commit_at'] = CarbonImmutable::parse($parts[1])->utc()->toIso8601String();
                $metadata['commit_subject'] = mb_substr($parts[2], 0, 1000);
                $metadata['git_state'] = $bare['output'] === 'true' ? 'Bare repository' : ($metadata['branch'] ? 'Git repository' : 'Detached HEAD');
            } else {
                $ref = $metadata['branch'] ? $this->git($path, ['show-ref', '--verify', '--quiet', 'refs/heads/'.$metadata['branch']]) : null;
                if (! $ref || $ref['exit_code'] !== 1) {
                    throw new RuntimeException('Git metadata unavailable');
                }
                $metadata['git_state'] = $bare['output'] === 'true' ? 'Empty bare repository' : 'Empty repository';
            }
        } catch (Throwable $exception) {
            $metadata['scan_error'] = $exception instanceof RuntimeException ? $exception->getMessage() : 'Git metadata unavailable';
        }

        return $metadata;
    }

    private function git(string $path, array $arguments): array
    {
        $executable = (new ExecutableFinder)->find('git');
        if (! $executable) {
            return ['state' => 'Executable unavailable', 'output' => '', 'exit_code' => null];
        }
        if (PHP_OS_FAMILY === 'Darwin' && $executable === '/usr/bin/git' && is_executable('/Library/Developer/CommandLineTools/usr/bin/git')) {
            $executable = '/Library/Developer/CommandLineTools/usr/bin/git';
        }

        return app(RunInspectionProcess::class)->handle([$executable, '--no-optional-locks', '--no-replace-objects', '-c', 'core.fsmonitor=false', '-c', 'core.hooksPath=/dev/null', '-C', $path, ...$arguments], '/', [
            'GIT_TERMINAL_PROMPT' => '0', 'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_SYSTEM' => '/dev/null',
        ]);
    }
}
