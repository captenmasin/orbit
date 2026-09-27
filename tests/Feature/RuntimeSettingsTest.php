<?php

namespace Tests\Feature;

use App\Actions\CheckDependencies;
use App\Actions\InvalidateRuntimeResults;
use App\Actions\ProbeRuntimes;
use App\Actions\QueueDependencyChecks;
use App\Actions\QueueInspection;
use App\Actions\ReadDependencies;
use App\Actions\RunInspectionProcess;
use App\Models\PackageRoot;
use App\Models\ProjectFolder;
use App\WorkspacePreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Native\Desktop\Dialog;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class RuntimeSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_overrides_win_and_a_reused_probe_reads_changed_globals_and_restores_detection(): void
    {
        Storage::fake('local');
        $global = $this->executable('tools/global-php');
        $override = $this->executable('tools/root-php');
        $automatic = $this->executable('automatic/php');
        $paths = [...array_fill_keys(ProbeRuntimes::TOOLS, '/missing-tool'), 'php' => $global];
        app(WorkspacePreferences::class)->merge(['tools' => ['paths' => $paths]]);
        $this->mock(RunInspectionProcess::class)->shouldReceive('handle')->times(3)->andReturnUsing(fn (): array => ['state' => 'Current', 'output' => 'PHP 8.5.3 (cli)', 'exit_code' => 0]);
        $probe = app(ProbeRuntimes::class);

        $globalResult = $probe->handle(Storage::path('project'))['php'];
        $rootResult = $probe->handle(Storage::path('project'), ['php' => $override])['php'];
        app(WorkspacePreferences::class)->merge(['tools' => ['paths' => [...$paths, 'php' => null]]]);
        $previousPath = getenv('PATH');
        try {
            putenv('PATH='.dirname($automatic));
            $autoResult = $probe->handle(Storage::path('project'))['php'];
        } finally {
            putenv($previousPath === false ? 'PATH' : 'PATH='.$previousPath);
        }

        $this->assertSame([$global, 'global', '8.5.3'], [$globalResult['path'], $globalResult['source'], $globalResult['version']]);
        $this->assertSame([$override, 'root'], [$rootResult['path'], $rootResult['source']]);
        $this->assertSame([$automatic, 'automatic'], [$autoResult['path'], $autoResult['source']]);
    }

    public function test_an_invalid_explicit_global_never_falls_back_to_automatic_detection(): void
    {
        Storage::fake('local');
        $automatic = $this->executable('automatic/php');
        app(WorkspacePreferences::class)->merge(['tools' => ['paths' => array_fill_keys(ProbeRuntimes::TOOLS, '/missing-tool')]]);
        $this->mock(RunInspectionProcess::class)->shouldNotReceive('handle');
        $previousPath = getenv('PATH');
        try {
            putenv('PATH='.dirname($automatic));
            $result = app(ProbeRuntimes::class)->handle(Storage::path('project'))['php'];
        } finally {
            putenv($previousPath === false ? 'PATH' : 'PATH='.$previousPath);
        }

        $this->assertSame('Missing executable', $result['state']);
        $this->assertSame('/missing-tool', $result['path']);
        $this->assertSame('global', $result['source']);
    }

    public function test_global_validation_rejects_project_bundled_and_wrapper_executables_before_running_them(): void
    {
        Storage::fake('local');
        Storage::makeDirectory('project');
        ProjectFolder::factory()->create(['path' => Storage::path('project')]);
        $project = $this->executable('project/php');
        $bundled = $this->executable('tools/Electron.app/Contents/MacOS/node');
        Storage::put('tools/composer', '#!/bin/sh');
        chmod(Storage::path('tools/composer'), 0755);
        $this->mock(RunInspectionProcess::class)->shouldNotReceive('handle');

        try {
            app(ProbeRuntimes::class)->validatePaths(['php' => $project, 'node' => $bundled, 'composer' => Storage::path('tools/composer'), 'npm' => '/missing']);
            $this->fail('Unsafe executable paths should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame([
                'paths.php' => ['Project executables are not probed'],
                'paths.node' => ['Orbit bundled executable excluded'],
                'paths.composer' => ['Unsupported wrapper; select the underlying executable'],
                'paths.npm' => ['Missing executable'],
            ], $exception->errors());
        }
    }

    public function test_global_edits_stale_affected_results_and_clear_tokens_without_losing_root_overrides_or_successes(): void
    {
        $folder = ProjectFolder::factory()->create();
        $affected = $folder->packageRoots()->sole();
        $unchanged = PackageRoot::factory()->for($folder, 'folder')->create(['executable_overrides' => ['php' => '/root/php']]);
        $snapshot = ['runtimes' => [
            'php' => ['state' => 'Current', 'version' => '8.5.0', 'path' => '/old/php', 'scanned_at' => '2026-09-01T00:00:00Z'],
            'composer' => ['state' => 'Current', 'version' => '2.9.0', 'path' => '/root/composer'],
            'node' => ['state' => 'Current', 'version' => '22.0.0', 'path' => '/old/node'],
        ]];
        foreach ([$affected, $unchanged] as $root) {
            $root->forceFill(['snapshot' => $snapshot, 'scan_state' => 'Current', 'scan_token' => 'scan', 'dependency_check_token' => 'check', 'scan_attempted_at' => now()])->save();
        }
        $affected->update(['executable_overrides' => ['composer' => '/root/composer']]);

        $this->savePaths(['php' => '/new/php']);

        $affected->refresh();
        $this->assertSame('Stale', $affected->scan_state);
        $this->assertSame('Stale', $affected->snapshot['runtimes']['php']['state']);
        $this->assertSame('8.5.0', $affected->snapshot['runtimes']['php']['version']);
        $this->assertSame('/old/php', $affected->snapshot['runtimes']['php']['path']);
        $this->assertSame('2026-09-01T00:00:00Z', $affected->snapshot['runtimes']['php']['scanned_at']);
        $this->assertSame('Stale', $affected->snapshot['runtimes']['composer']['state']);
        $this->assertSame('Current', $affected->snapshot['runtimes']['node']['state']);
        $this->assertSame(['composer' => '/root/composer'], $affected->executable_overrides);
        $this->assertNull($affected->scan_token);
        $this->assertNull($affected->dependency_check_token);
        $this->assertNull($affected->scan_attempted_at);
        $this->assertSame($snapshot, $unchanged->fresh()->snapshot);
        $this->assertSame('scan', $unchanged->fresh()->scan_token);
    }

    public function test_a_scan_using_old_global_paths_cannot_publish_over_a_previous_success(): void
    {
        Storage::fake('local');
        $folder = $this->folder();
        $root = $folder->packageRoots()->sole();
        $oldPhp = $this->executable('tools/old-php');
        $newPhp = $this->executable('tools/new-php');
        app(WorkspacePreferences::class)->merge(['tools' => ['paths' => [...array_fill_keys(ProbeRuntimes::TOOLS, '/missing-tool'), 'php' => $oldPhp]]]);
        $root->forceFill(['snapshot' => ['runtimes' => ['php' => ['state' => 'Current', 'version' => '8.4.0', 'path' => '/previous/php']]]])->save();
        $this->mock(RunInspectionProcess::class)->shouldReceive('handle')->once()->andReturnUsing(function () use ($newPhp): array {
            $this->savePaths(['php' => $newPhp]);

            return ['state' => 'Current', 'output' => 'PHP 8.5.0 (cli)', 'exit_code' => 0];
        });

        app(QueueInspection::class)->handle($root);
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'inspection', '--sleep' => 0])->assertExitCode(0);

        $root->refresh();
        $this->assertSame('Stale', $root->scan_state);
        $this->assertSame('8.4.0', $root->snapshot['runtimes']['php']['version']);
        $this->assertSame('/previous/php', $root->snapshot['runtimes']['php']['path']);
        $this->assertNull($root->scan_token);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_a_pending_dependency_job_is_invalidated_by_a_global_path_change(): void
    {
        Storage::fake('local');
        $root = $this->folder()->packageRoots()->sole();
        $root->forceFill(['snapshot' => ['runtimes' => ['php' => ['state' => 'Current', 'version' => '8.4.0']]]])->save();
        app(QueueDependencyChecks::class)->handle();
        Http::preventStrayRequests();

        $this->savePaths(['php' => '/new/php']);
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'dependencies', '--sleep' => 0])->assertExitCode(0);

        $this->assertSame('Stale', $root->fresh()->snapshot['runtimes']['php']['state']);
        $this->assertNull($root->fresh()->security);
        $this->assertNull($root->fresh()->dependency_check_token);
        Http::assertNothingSent();
    }

    public function test_a_dependency_check_preserves_stale_runtime_data_even_if_its_model_was_loaded_before_the_edit(): void
    {
        Storage::fake('local');
        $root = $this->folder()->packageRoots()->sole();
        $root->forceFill(['snapshot' => ['runtimes' => ['php' => ['state' => 'Current', 'version' => '8.4.0', 'path' => '/previous/php']]]])->save();
        $this->savePaths(['php' => '/new/php']);
        Http::preventStrayRequests();

        app(CheckDependencies::class)->handle($root);

        $root->refresh();
        $this->assertSame('Stale', $root->snapshot['runtimes']['php']['state']);
        $this->assertSame('8.4.0', $root->snapshot['runtimes']['php']['version']);
        $this->assertSame('/previous/php', $root->snapshot['runtimes']['php']['path']);
        Http::assertNothingSent();
        $this->assertTrue(app(QueueInspection::class)->handle($root, onlyStale: true));
        $this->assertSame('Queued', $root->fresh()->scan_state);
    }

    public function test_an_in_flight_dependency_check_cannot_publish_after_global_paths_change(): void
    {
        Storage::fake('local');
        $folder = $this->folder();
        $root = $folder->packageRoots()->sole();
        Storage::put('project/composer.json', '{"require":{"vendor/library":"^2.0"}}');
        Storage::put('project/composer.lock', '{"packages":[{"name":"vendor/library","version":"2.0.0"}]}');
        $root->forceFill(['snapshot' => ['runtimes' => ['php' => ['state' => 'Current', 'version' => '8.4.0']]]])->save();
        Http::preventStrayRequests();
        Http::fake([
            'https://repo.packagist.org/p2/vendor/library.json' => function () {
                $this->savePaths(['php' => '/new/php']);

                return Http::response(['packages' => ['vendor/library' => [['version' => '3.0.0']]]]);
            },
            'https://api.osv.dev/v1/querybatch' => Http::response(['results' => [[]]]),
        ]);

        $this->postJson('/projects/'.$folder->project_id.'/roots/'.$root->id.'/check')->assertInvalid(['root' => 'This package location changed. Check it again.']);

        $root->refresh();
        $this->assertSame('Stale', $root->scan_state);
        $this->assertSame('Stale', $root->snapshot['runtimes']['php']['state']);
        $this->assertNull($root->outdated);
        $this->assertNull($root->security);
        Http::assertSentCount(2);
    }

    public function test_saving_paths_validates_executables_and_atomically_invalidates_the_previous_snapshot(): void
    {
        Storage::fake('local');
        $root = $this->folder()->packageRoots()->sole();
        $root->forceFill(['snapshot' => ['runtimes' => ['php' => ['state' => 'Current', 'version' => '8.4.0']]], 'scan_token' => 'old-token'])->save();
        $php = $this->executable('tools/php');
        $this->mock(RunInspectionProcess::class)->shouldNotReceive('handle');

        $this->putJson('/settings/tools', ['revision' => 1, 'paths' => ['php' => '/missing/php']])->assertInvalid(['paths.php' => 'Missing executable']);

        $this->assertSame(1, app(WorkspacePreferences::class)->snapshot()['revision']);
        $this->assertSame('old-token', $root->fresh()->scan_token);
        $this->putJson('/settings/tools', ['revision' => 1, 'paths' => ['php' => $php]])->assertJsonPath('preferences.values.tools.paths.php', $php);
        $this->assertSame($php, app(WorkspacePreferences::class)->get('tools.paths.php'));
        $this->assertSame('Stale', $root->fresh()->snapshot['runtimes']['php']['state']);
        $this->assertSame('8.4.0', $root->fresh()->snapshot['runtimes']['php']['version']);
        $this->assertNull($root->fresh()->scan_token);
    }

    public function test_probing_draft_paths_reports_effective_path_and_version_without_saving(): void
    {
        Storage::fake('local');
        $php = $this->executable('tools/php');
        $this->mock(RunInspectionProcess::class)->shouldReceive('handle')->once()->andReturn(['state' => 'Current', 'output' => 'PHP 8.5.3 (cli)', 'exit_code' => 0]);

        $this->postJson('/settings/tools/probe', ['paths' => [...array_fill_keys(ProbeRuntimes::TOOLS, '/missing-tool'), 'php' => $php]])
            ->assertJsonPath('runtimes.php.path', $php)->assertJsonPath('runtimes.php.version', '8.5.3')->assertJsonPath('runtimes.php.source', 'global');

        $this->assertNull(app(WorkspacePreferences::class)->get('tools.paths.php'));
        $this->postJson('/settings/tools/probe', ['paths' => ['shell' => '/bin/sh']])->assertInvalid('paths');
        $this->postJson('/settings/tools/pick', ['tool' => 'php'])->assertInvalid(['path' => 'Enter the executable path in browser development.']);
    }

    #[TestWith(['/selected/php'])]
    #[TestWith([null])]
    public function test_native_executable_selection_returns_the_selected_path_or_cancellation_without_saving(?string $path): void
    {
        config(['nativephp-internal.running' => true]);
        $this->mock(Dialog::class)->shouldReceive('files->withHiddenFiles->title->button->asSheet->open')->once()->andReturn($path);

        $this->postJson('/settings/tools/pick', ['tool' => 'php'])->assertExactJson(['path' => $path]);

        $this->assertNull(app(WorkspacePreferences::class)->get('tools.paths.php'));
    }

    public function test_native_picker_failure_reports_a_recoverable_error(): void
    {
        config(['nativephp-internal.running' => true]);
        $this->mock(Dialog::class)->shouldReceive('files->withHiddenFiles->title->button->asSheet->open')->once()->andThrow(new RuntimeException('Native bridge unavailable'));

        $this->postJson('/settings/tools/pick', ['tool' => 'php'])->assertInvalid(['path' => 'The file picker could not open. Try again.']);
    }

    public function test_an_in_flight_updates_request_cannot_publish_after_runtime_defaults_change(): void
    {
        Storage::fake('local');
        $folder = $this->folder();
        $root = $folder->packageRoots()->sole();
        Storage::put('project/composer.json', '{"require":{"vendor/library":"^2.0"}}');
        Storage::put('project/composer.lock', '{"packages":[{"name":"vendor/library","version":"2.0.0"}]}');
        $snapshot = app(ReadDependencies::class)->handle($folder->path);
        $root->forceFill(['snapshot' => $snapshot, 'scan_state' => 'Current'])->save();
        Http::preventStrayRequests();
        Http::fake([
            'https://repo.packagist.org/p2/vendor/library.json' => function () {
                $this->savePaths(['php' => '/new/php']);

                return Http::response(['packages' => ['vendor/library' => [['version' => '3.0.0']]]]);
            },
        ]);

        $this->postJson('/projects/'.$folder->project_id.'/roots/'.$root->id.'/outdated')->assertInvalid(['root' => 'This package root changed. Check for updates again.']);

        $this->assertSame('Stale', $root->fresh()->scan_state);
        $this->assertNull($root->fresh()->outdated);
        Http::assertSentCount(1);
    }

    private function folder(): ProjectFolder
    {
        Storage::makeDirectory('project');

        return ProjectFolder::factory()->create(['path' => Storage::path('project')]);
    }

    private function executable(string $path): string
    {
        Storage::put($path, hex2bin('cffaedfe'));
        chmod(Storage::path($path), 0755);

        return Storage::path($path);
    }

    private function savePaths(array $changes): void
    {
        DB::transaction(function () use ($changes): void {
            $preferences = app(WorkspacePreferences::class);
            $before = $preferences->get('tools.paths');
            $after = array_replace($before, $changes);
            $preferences->merge(['tools' => ['paths' => $after]]);
            app(InvalidateRuntimeResults::class)->handle($before, $after);
        });
    }
}
