<?php

namespace Tests\Feature;

use App\Actions\InspectFolder;
use App\Actions\ProbeRuntimes;
use App\Actions\QueueInspection;
use App\Actions\RunInspectionProcess;
use App\Jobs\ScanLocalTarget;
use App\Models\PackageRoot;
use App\Models\ProjectFolder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class LocalInspectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_git_reads_containing_roots_worktrees_detached_and_bare_head_committer_dates(): void
    {
        Storage::fake('local');
        $path = $this->folder('repo');
        $this->git($path, ['init', '-b', 'main']);
        $empty = app(InspectFolder::class)->gitMetadata($path);
        $this->assertSame('Empty repository', $empty['git_state']);
        $this->assertNull($empty['last_commit_at']);
        $this->git($path, ['commit', '--allow-empty', '-m', 'First local commit']);
        $this->git($path, ['remote', 'add', 'origin', 'https://user:secret@example.com/private.git']);
        $nested = $this->folder('repo/packages/web');

        $result = app(InspectFolder::class)->gitMetadata($nested);

        $this->assertSame($path, $result['git_root']);
        $this->assertSame('main', $result['branch']);
        $this->assertSame('2026-02-03T05:30:00+00:00', $result['last_commit_at']);
        $this->assertSame('First local commit', $result['commit_subject']);
        $this->assertNull($result['git_remote']);
        $worktree = Storage::path('worktree');
        $this->git($path, ['worktree', 'add', '-b', 'preview', $worktree]);
        $this->assertSame('preview', app(InspectFolder::class)->gitMetadata($worktree)['branch']);
        $this->git($path, ['checkout', '--detach']);
        $this->assertSame('Detached HEAD', app(InspectFolder::class)->gitMetadata($path)['git_state']);
        $bare = $this->folder('bare');
        $this->git($path, ['clone', '--bare', $path, $bare]);
        $this->assertSame('Bare repository', app(InspectFolder::class)->gitMetadata($bare)['git_state']);
        $plain = $this->folder('plain');
        $this->assertSame('Not a Git repository', app(InspectFolder::class)->gitMetadata($plain)['git_state']);
        Storage::put('plain/.git', 'gitdir: missing');
        $this->assertSame('Git metadata unavailable', app(InspectFolder::class)->gitMetadata($plain)['scan_error']);
    }

    public function test_git_errors_retain_last_success_but_a_successful_empty_checkout_clears_old_commit(): void
    {
        Storage::fake('local');
        $folder = ProjectFolder::factory()->create(['path' => $this->folder('repo'), 'last_commit_hash' => str_repeat('a', 40), 'last_commit_at' => '2026-01-01 00:00:00', 'scanned_at' => '2026-01-02 00:00:00']);
        $this->git($folder->path, ['init', '-b', 'main']);
        Storage::put('repo/.git/config', 'malformed config');
        $this->runScan($folder);
        $this->assertSame(str_repeat('a', 40), $folder->fresh()->last_commit_hash);
        $this->assertSame('2026-01-02', $folder->fresh()->scanned_at->toDateString());
        $this->assertSame('Stale', $folder->fresh()->scan_state);
        Storage::put('repo/.git/config', "[core]\nrepositoryformatversion = 0\nbare = false\n");

        $this->runScan($folder);

        $this->assertNull($folder->fresh()->last_commit_hash);
        $this->assertNull($folder->fresh()->last_commit_at);
        $this->assertSame('Empty repository', $folder->fresh()->git_state);
        $this->assertSame('Current', $folder->fresh()->scan_state);
    }

    public function test_queue_requests_coalesce_recover_abandoned_scans_and_do_not_change_project_revision(): void
    {
        Storage::fake('local');
        $this->freezeTime();
        $folder = ProjectFolder::factory()->create(['path' => $this->folder('plain')]);
        $project = $folder->project;
        $url = '/projects/'.$project->id.'/inspection';
        $input = ['kind' => 'folder', 'id' => $folder->id];
        $this->postJson($url, $input)->assertExactJson(['queued' => 1]);
        $this->postJson($url, $input)->assertExactJson(['queued' => 0]);
        $this->assertDatabaseCount('jobs', 1);
        $payload = json_decode(DB::table('jobs')->sole()->payload, true);
        $this->assertStringNotContainsString($folder->path, $payload['data']['command']);
        $firstToken = $folder->fresh()->scan_token;
        $this->workOnce();
        $this->assertSame('Current', $folder->fresh()->scan_state);
        $this->assertSame(1, $project->fresh()->revision);
        $this->postJson($url, [...$input, 'only_stale' => true])->assertExactJson(['queued' => 0]);
        $folder->refresh()->forceFill(['scan_state' => 'Scanning', 'scan_started_at' => now()->subSeconds(91)])->save();
        $this->postJson($url, [...$input, 'only_stale' => true])->assertExactJson(['queued' => 1]);
        $this->assertNotSame($firstToken, $folder->fresh()->scan_token);
        $old = new ScanLocalTarget($folder->id, $firstToken, 'folder');
        app()->call([$old, 'handle']);
        $this->assertSame('Queued', $folder->fresh()->scan_state);
        $this->get('/projects/'.$project->id)->assertInertia(fn (Assert $page) => $page->where('inspection.0.scan_state', 'Queued')->missing('inspection.0.scan_token')->where('selectedProject.revision', 1));
    }

    public function test_root_selection_is_scoped_canonical_and_uses_its_own_revision(): void
    {
        Storage::fake('local');
        $folder = ProjectFolder::factory()->create(['path' => $this->folder('project')]);
        $nested = $this->folder('project/packages/web');
        $outside = $this->folder('outside');
        symlink($outside, $folder->path.'/escape');
        $url = '/projects/'.$folder->project_id.'/roots';
        $input = ['action' => 'save', 'folder_id' => $folder->id, 'path' => $nested, 'executable_overrides' => ['php' => '/usr/local/bin/php']];
        $this->postJson($url, $input)->assertOk();
        $root = $folder->packageRoots()->where('relative_path', 'packages/web')->sole();
        $this->assertSame(['php' => '/usr/local/bin/php'], $root->executable_overrides);
        $this->assertSame($nested, $root->resolvePath());
        $this->postJson($url, $input)->assertUnprocessable()->assertJsonValidationErrors('path');
        $this->postJson($url, [...$input, 'path' => $folder->path.'/escape'])->assertUnprocessable()->assertJsonValidationErrors('path');
        $foreign = ProjectFolder::factory()->create();
        $this->postJson($url, [...$input, 'folder_id' => $foreign->id])->assertNotFound();
        $this->postJson('/projects/'.$folder->project_id.'/inspection', ['kind' => 'root', 'id' => $foreign->packageRoots()->sole()->id])->assertNotFound();
        $edit = [...$input, 'id' => $root->id, 'revision' => 1];
        $this->postJson($url, $edit)->assertOk();
        $this->postJson($url, [...$edit, 'executable_overrides' => ['php' => '/stale/php']])->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->assertSame(2, $root->fresh()->revision);
        $this->assertSame(1, $folder->project->fresh()->revision);
        $this->postJson($url, [...$edit, 'revision' => 2, 'action' => 'delete'])->assertOk();
        $this->assertDatabaseMissing('package_roots', ['id' => $root->id]);
        $this->assertDirectoryExists($nested);
    }

    public function test_relinking_preserves_relative_roots_and_invalidates_old_results_and_jobs(): void
    {
        Storage::fake('local');
        $folder = ProjectFolder::factory()->create(['path' => $this->folder('old')]);
        $root = $folder->packageRoots()->create(['relative_path' => 'packages/web', 'executable_overrides' => ['php' => '/custom/php']]);
        $root->forceFill(['snapshot' => ['previous' => true]])->save();
        app(QueueInspection::class)->handle($root);
        $new = $this->folder('new');
        $this->put('/projects/'.$folder->project_id, [
            'name' => $folder->project->name, 'status' => 'Active', 'revision' => 1,
            'folders' => [['id' => $folder->id, 'path' => $new, 'repository_id' => null]],
        ])->assertRedirect();
        $this->workOnce();
        $this->assertSame('packages/web', $root->fresh()->relative_path);
        $this->assertSame(['php' => '/custom/php'], $root->fresh()->executable_overrides);
        $this->assertNull($root->fresh()->snapshot);
        $this->assertNull($root->fresh()->scan_token);
        $this->assertSame('Not scanned', $root->fresh()->scan_state);
    }

    public function test_partial_snapshot_survives_a_missing_folder_and_keeps_catalog_edits(): void
    {
        Storage::fake('local');
        $folder = ProjectFolder::factory()->create(['path' => $this->folder('source')]);
        $root = $folder->packageRoots()->sole();
        $root->update(['executable_overrides' => array_fill_keys(ProbeRuntimes::TOOLS, '/missing-tool')]);
        Storage::put('source/package.json', '{"dependencies":{"vue":"^3"}}');
        Storage::put('source/composer.json', 'malformed');
        $this->runScan($root);
        $snapshot = $root->fresh()->snapshot;
        $this->assertSame('Partial', $root->fresh()->scan_state);
        $this->assertSame('vue', $snapshot['files']['package.json']['entries'][0]['name']);
        $this->assertSame('Malformed file', $snapshot['files']['composer.json']['state']);
        $this->assertSame('Missing executable', $snapshot['runtimes']['php']['state']);
        $folder->project->update(['name' => 'User edit']);
        Storage::deleteDirectory('source');

        $this->runScan($root);

        $this->assertSame($snapshot, $root->fresh()->snapshot);
        $this->assertSame('Missing folder', $root->fresh()->scan_error);
        $this->assertSame('User edit', $folder->project->fresh()->name);
        $this->assertSame(1, $folder->project->fresh()->revision);
    }

    public function test_settings_changed_during_a_probe_prevent_an_obsolete_job_from_publishing(): void
    {
        Storage::fake('local');
        $folder = ProjectFolder::factory()->create(['path' => $this->folder('source')]);
        $root = $folder->packageRoots()->sole();
        $root->update(['executable_overrides' => [...array_fill_keys(ProbeRuntimes::TOOLS, '/missing-tool'), 'php' => PHP_BINARY]]);
        $this->mock(RunInspectionProcess::class)->shouldReceive('handle')->once()->andReturnUsing(function () use ($folder, $root): array {
            $this->postJson('/projects/'.$folder->project_id.'/roots', [
                'action' => 'save', 'id' => $root->id, 'revision' => 1, 'folder_id' => $folder->id,
                'path' => $folder->path, 'executable_overrides' => ['php' => '/new/php'],
            ])->assertOk();

            return ['state' => 'Current', 'output' => 'PHP 8.5.0 (cli)', 'exit_code' => 0];
        });

        $this->runScan($root);

        $this->assertNull($root->fresh()->snapshot);
        $this->assertSame(2, $root->fresh()->revision);
        $this->assertSame('Not scanned', $root->fresh()->scan_state);
    }

    public function test_deleting_a_folder_during_a_probe_does_not_recreate_any_of_its_records(): void
    {
        Storage::fake('local');
        $folder = ProjectFolder::factory()->create(['path' => $this->folder('source')]);
        $root = $folder->packageRoots()->sole();
        $root->update(['executable_overrides' => [...array_fill_keys(ProbeRuntimes::TOOLS, '/missing-tool'), 'php' => PHP_BINARY]]);
        $this->mock(RunInspectionProcess::class)->shouldReceive('handle')->once()->andReturnUsing(function () use ($folder): array {
            $folder->delete();

            return ['state' => 'Current', 'output' => 'PHP 8.5.0 (cli)', 'exit_code' => 0];
        });

        $this->runScan($root);

        $this->assertDatabaseMissing('project_folders', ['id' => $folder->id]);
        $this->assertDatabaseMissing('package_roots', ['id' => $root->id]);
        $this->assertDatabaseHas('projects', ['id' => $folder->project_id]);
    }

    private function runScan(ProjectFolder|PackageRoot $target): void
    {
        $this->assertTrue(app(QueueInspection::class)->handle($target));
        $this->workOnce();
    }

    private function workOnce(): void
    {
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'inspection', '--sleep' => 0])->assertExitCode(0);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    private function folder(string $name): string
    {
        Storage::makeDirectory($name);

        return Storage::path($name);
    }

    private function git(string $path, array $command): void
    {
        (new Process(['git', '-C', $path, '-c', 'user.name=Orbit Test', '-c', 'user.email=orbit@example.test', '-c', 'commit.gpgsign=false', ...$command], env: [
            'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_AUTHOR_DATE' => '2025-01-01T00:00:00+00:00', 'GIT_COMMITTER_DATE' => '2026-02-03T11:00:00+05:30',
        ]))->mustRun();
    }
}
