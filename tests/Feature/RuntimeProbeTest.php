<?php

namespace Tests\Feature;

use App\Actions\ProbeRuntimes;
use App\Actions\RunInspectionProcess;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RuntimeProbeTest extends TestCase
{
    public function test_supported_tools_use_bounded_version_commands_outside_the_project(): void
    {
        Storage::fake('local');
        $overrides = $this->missingTools();
        $overrides['php'] = $this->executable('tools/php', hex2bin('cffaedfe'));
        $overrides['node'] = $this->executable('tools/node', hex2bin('cffaedfe'));
        $overrides['composer'] = $this->executable('tools/composer', '<?php Phar::mapPhar(); __HALT_COMPILER();');
        foreach (['npm' => 'npm-cli.js', 'pnpm' => 'pnpm.cjs', 'yarn' => 'yarn.js'] as $tool => $file) {
            $overrides[$tool] = $this->executable('tools/'.$tool.'/bin/'.$file, '#!/usr/bin/env node');
            Storage::put('tools/'.$tool.'/package.json', json_encode(['name' => $tool]));
        }
        $commands = [];
        $this->mock(RunInspectionProcess::class)->shouldReceive('handle')->times(6)->andReturnUsing(function ($command, $directory, $environment, $includeStderr) use (&$commands, $overrides) {
            $commands[] = $command;
            $this->assertSame('/', $directory);
            $this->assertSame('0', $environment['COREPACK_ENABLE_NETWORK']);
            $this->assertSame('0', $environment['SHELL_VERBOSITY']);
            $this->assertTrue($includeStderr);
            $this->assertNotSame($environment['npm_config_userconfig'], $environment['npm_config_globalconfig']);
            $output = $command[0] === $overrides['php'] ? (in_array('-v', $command) ? 'PHP 8.5.3 (cli)' : "PHP version 8.5.3\nComposer version 2.9.5 2026-01-01") : 'v22.22.2';

            return ['state' => 'Current', 'output' => $output, 'exit_code' => 0];
        });

        $results = app(ProbeRuntimes::class)->handle(Storage::path('project'), $overrides);

        $this->assertSame('8.5.3', $results['php']['version']);
        $this->assertSame('2.9.5', $results['composer']['version']);
        $this->assertSame('22.22.2', $results['node']['version']);
        $this->assertSame([$overrides['php'], '-n', '-v'], $commands[0]);
        $this->assertContains('--no-plugins', $commands[2]);
        $this->assertContains('--no-scripts', $commands[2]);
        $this->assertSame($overrides['node'], $commands[3][0]);
        $this->assertSame($overrides['npm'], $results['npm']['path']);
        $this->assertNotNull($results['npm']['scanned_at']);
    }

    public function test_failed_probe_retains_previous_version_with_its_original_source_and_timestamp(): void
    {
        Storage::fake('local');
        $php = $this->executable('tools/php', hex2bin('cffaedfe'));
        $this->mock(RunInspectionProcess::class)->shouldReceive('handle')->once()->andReturn(['state' => 'Timed out', 'output' => '', 'exit_code' => null]);
        $previous = ['php' => ['version' => '8.4.0', 'path' => '/previous/php', 'probe_directory' => '/', 'scanned_at' => '2026-01-01T00:00:00Z']];

        $result = app(ProbeRuntimes::class)->handle(Storage::path('project'), [...$this->missingTools(), 'php' => $php], $previous)['php'];

        $this->assertSame('Timed out', $result['state']);
        $this->assertNull($result['version']);
        $this->assertSame($previous['php'], $result['last_success']);
    }

    public function test_missing_project_bundled_and_wrapper_executables_are_never_launched(): void
    {
        Storage::fake('local');
        $overrides = $this->missingTools();
        $overrides['php'] = $this->executable('project/php', hex2bin('cffaedfe'));
        $overrides['node'] = $this->executable('tools/Electron.app/Contents/MacOS/Electron', hex2bin('cffaedfe'));
        $overrides['composer'] = $this->executable('tools/composer', '#!/bin/sh\ntouch should-not-run');
        $overrides['npm'] = $this->executable('tools/corepack/npm.js', '#!/usr/bin/env node');
        $this->mock(RunInspectionProcess::class)->shouldNotReceive('handle');

        $results = app(ProbeRuntimes::class)->handle(Storage::path('project'), $overrides);

        $this->assertSame('Project executables are not probed', $results['php']['state']);
        $this->assertSame('Orbit bundled executable excluded', $results['node']['state']);
        $this->assertStringStartsWith('Unsupported wrapper', $results['composer']['state']);
        $this->assertStringStartsWith('Unsupported wrapper', $results['npm']['state']);
        $this->assertSame('Missing executable', $results['pnpm']['state']);
    }

    public function test_process_boundary_strips_injection_environment_and_caps_stdout_and_stderr(): void
    {
        $keys = ['NODE_OPTIONS', 'NODE_PATH', 'PHPRC', 'PHP_INI_SCAN_DIR', 'GIT_CONFIG_COUNT', 'BASH_ENV'];
        $old = array_combine($keys, array_map('getenv', $keys));
        try {
            foreach ($keys as $key) {
                putenv($key.'=untrusted');
            }
            $result = app(RunInspectionProcess::class)->handle([PHP_BINARY, '-n', '-r', 'echo json_encode(array_map("getenv", '.var_export($keys, true).'));'], '/');
            $this->assertSame('Current', $result['state']);
            $this->assertSame(array_fill(0, count($keys), false), json_decode($result['output'], true));
        } finally {
            foreach ($old as $key => $value) {
                putenv($value === false ? $key : $key.'='.$value);
            }
        }
        $flood = app(RunInspectionProcess::class)->handle([PHP_BINARY, '-n', '-r', 'fwrite(STDERR, str_repeat("private output", 7000));'], '/');
        $this->assertSame('Output limit exceeded', $flood['state']);
        $this->assertSame('', $flood['output']);
        $stderr = app(RunInspectionProcess::class)->handle([PHP_BINARY, '-n', '-r', 'fwrite(STDERR, "Composer version 2.10.2");'], '/', includeStderr: true);
        $this->assertSame('Composer version 2.10.2', $stderr['output']);
    }

    public function test_process_timeout_stops_a_version_command_that_does_not_finish(): void
    {
        $result = app(RunInspectionProcess::class)->handle([PHP_BINARY, '-n', '-r', 'usleep(5000000);'], '/');

        $this->assertSame('Timed out', $result['state']);
        $this->assertSame('', $result['output']);
    }

    private function missingTools(): array
    {
        return array_fill_keys(ProbeRuntimes::TOOLS, Storage::path('missing'));
    }

    private function executable(string $file, string $contents): string
    {
        Storage::put($file, $contents);
        chmod(Storage::path($file), 0755);

        return Storage::path($file);
    }
}
