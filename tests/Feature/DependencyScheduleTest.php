<?php

namespace Tests\Feature;

use App\Actions\QueueDependencyChecks;
use App\Jobs\CheckDependenciesForRoot;
use App\Models\PackageRoot;
use App\Models\Project;
use App\Models\ProjectFolder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DependencyScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_queues_new_and_daily_due_locations_across_projects_and_folders(): void
    {
        $this->travelTo(Carbon::parse('2026-09-26 12:00:00'));
        $project = Project::factory()->create();
        $first = ProjectFolder::factory()->for($project)->create();
        $second = ProjectFolder::factory()->for($project)->create();
        $other = ProjectFolder::factory()->create();
        $new = $first->packageRoots()->sole();
        $boundary = PackageRoot::factory()->for($first, 'folder')->create(['dependency_check_attempted_at' => '2026-09-25 12:00:00']);
        $old = $second->packageRoots()->sole();
        $old->forceFill(['dependency_check_attempted_at' => '2026-09-24 12:00:00'])->save();
        $otherProject = $other->packageRoots()->sole();
        $recent = PackageRoot::factory()->for($other, 'folder')->create(['dependency_check_attempted_at' => '2026-09-25 12:00:01']);
        Queue::fake([CheckDependenciesForRoot::class]);

        app(QueueDependencyChecks::class)->handle();

        $expectedIds = [$new->id, $boundary->id, $old->id, $otherProject->id];
        sort($expectedIds);
        $jobs = Queue::pushed(CheckDependenciesForRoot::class);
        $this->assertSame($expectedIds, $jobs->map(fn (CheckDependenciesForRoot $job): string => $job->rootId)->sort()->values()->all());
        $this->assertTrue($jobs->every(fn (CheckDependenciesForRoot $job): bool => $job->connection === 'database' && $job->queue === 'dependencies' && $job->attemptedAt === '2026-09-26 12:00:00'));
        foreach ($expectedIds as $id) {
            $this->assertDatabaseHas('package_roots', ['id' => $id, 'dependency_check_attempted_at' => '2026-09-26 12:00:00']);
        }
        $this->assertDatabaseHas('package_roots', ['id' => $recent->id, 'dependency_check_attempted_at' => '2026-09-25 12:00:01']);
    }

    public function test_repeated_scheduler_ticks_do_not_duplicate_pending_checks(): void
    {
        $this->travelTo(Carbon::parse('2026-09-26 12:00:00'));
        $folder = ProjectFolder::factory()->create();
        $root = $folder->packageRoots()->sole();

        app(QueueDependencyChecks::class)->handle();
        $this->travel(1)->minutes();
        app(QueueDependencyChecks::class)->handle();

        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseHas('jobs', ['queue' => 'dependencies']);
        $this->assertDatabaseHas('package_roots', ['id' => $root->id, 'dependency_check_attempted_at' => '2026-09-26 12:00:00']);
    }

    public function test_worker_persists_current_versions_and_security_findings(): void
    {
        $this->travelTo(Carbon::parse('2026-09-26 12:00:00'));
        Storage::fake('local');
        $folder = $this->folder('project');
        $root = $folder->packageRoots()->sole();
        $root->forceFill(['snapshot' => ['runtimes' => ['php' => ['version' => '8.5']]]])->save();
        Http::preventStrayRequests();
        Http::fake([
            'https://repo.packagist.org/p2/vendor/library.json' => Http::response(['packages' => ['vendor/library' => [['version' => '3.0.0']]]]),
            'https://api.osv.dev/v1/querybatch' => Http::response(['results' => [['vulns' => [['id' => 'GHSA-example']]]]]),
            'https://api.osv.dev/v1/vulns/GHSA-example' => Http::response([
                'id' => 'GHSA-example', 'modified' => '2026-09-26T00:00:00Z', 'summary' => 'Unsafe input',
                'database_specific' => ['severity' => 'HIGH'],
                'affected' => [['package' => ['name' => 'vendor/library', 'ecosystem' => 'Packagist'], 'ranges' => [['type' => 'SEMVER', 'events' => [['introduced' => '0'], ['fixed' => '2.0.1']]]]]],
            ]),
        ]);

        app(QueueDependencyChecks::class)->handle();
        $this->workOnce();

        $root->refresh();
        $this->assertSame('Current', $root->scan_state);
        $this->assertSame('2.0.0', $root->snapshot['files']['composer.lock']['entries'][0]['version']);
        $this->assertSame('8.5', $root->snapshot['runtimes']['php']['version']);
        $this->assertSame('3.0.0', $root->outdated['packages'][0]['latest']);
        $this->assertSame('High', $root->security['packages'][0]['advisories'][0]['severity']);
        $this->assertSame($root->snapshot['fingerprint'], $root->security['fingerprint']);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertSentCount(3);
    }

    public function test_missing_folder_keeps_previous_results_and_does_not_block_another_location(): void
    {
        $this->travelTo(Carbon::parse('2026-09-26 12:00:00'));
        Storage::fake('local');
        $missing = ProjectFolder::factory()->create()->packageRoots()->sole();
        $previous = [
            'snapshot' => ['files' => ['composer.lock' => ['entries' => [['name' => 'old/package', 'version' => '1.0.0']]]]],
            'outdated' => ['packages' => [['name' => 'old/package', 'latest' => '2.0.0']]],
            'security' => ['packages' => [['name' => 'old/package', 'advisories' => [['id' => 'GHSA-old']]]]],
        ];
        $missing->forceFill($previous)->save();
        app(QueueDependencyChecks::class)->handle();
        $healthy = $this->folder('healthy')->packageRoots()->sole();
        app(QueueDependencyChecks::class)->handle();
        $this->fakeHealthyRegistry();

        $this->workOnce();
        $this->workOnce();

        $missing->refresh();
        $this->assertSame('Stale', $missing->scan_state);
        $this->assertSame('Missing folder', $missing->scan_error);
        $this->assertSame($previous['snapshot'], $missing->snapshot);
        $this->assertSame($previous['outdated'], $missing->outdated);
        $this->assertSame($previous['security'], $missing->security);
        $this->assertSame('Current', $healthy->fresh()->scan_state);
        $this->assertSame('3.0.0', $healthy->fresh()->outdated['packages'][0]['latest']);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertSentCount(2);
    }

    public function test_manual_check_supersedes_a_pending_check_in_the_same_second_without_more_requests(): void
    {
        $this->travelTo(Carbon::parse('2026-09-26 12:00:00'));
        Storage::fake('local');
        $folder = $this->folder('project');
        $root = $folder->packageRoots()->sole();
        app(QueueDependencyChecks::class)->handle();
        $this->fakeHealthyRegistry();
        $this->postJson("/projects/{$folder->project_id}/roots/{$root->id}/check")->assertExactJson(['ok' => true]);
        $outdated = $root->fresh()->outdated;
        $security = $root->fresh()->security;

        $this->workOnce();

        $this->assertSame($outdated, $root->fresh()->outdated);
        $this->assertSame($security, $root->fresh()->security);
        $this->assertDatabaseHas('package_roots', ['id' => $root->id, 'dependency_check_attempted_at' => '2026-09-26 12:00:00']);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertSentCount(2);
    }

    public function test_duplicate_pending_payload_cannot_check_the_location_twice(): void
    {
        $this->travelTo(Carbon::parse('2026-09-26 12:00:00'));
        Storage::fake('local');
        $root = $this->folder('project')->packageRoots()->sole();
        app(QueueDependencyChecks::class)->handle();
        $root->refresh();
        Queue::connection('database')->push(new CheckDependenciesForRoot($root->id, '2026-09-26 12:00:00', $root->dependency_check_token), '', 'dependencies');
        $this->fakeHealthyRegistry();

        $this->workOnce();
        $this->workOnce();

        $this->assertSame('3.0.0', $root->fresh()->outdated['packages'][0]['latest']);
        $this->assertNull($root->fresh()->dependency_check_token);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertSentCount(2);
    }

    public function test_deleted_location_is_ignored_without_network_requests(): void
    {
        $this->freezeTime();
        $folder = ProjectFolder::factory()->create();
        $root = $folder->packageRoots()->sole();
        app(QueueDependencyChecks::class)->handle();
        $root->delete();
        Http::preventStrayRequests();

        $this->workOnce();

        $this->assertModelMissing($root);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
    }

    private function folder(string $name): ProjectFolder
    {
        Storage::makeDirectory($name);
        Storage::put($name.'/composer.json', '{"require":{"vendor/library":"^2.0"}}');
        Storage::put($name.'/composer.lock', '{"packages":[{"name":"vendor/library","version":"2.0.0"}]}');

        return ProjectFolder::factory()->create(['path' => Storage::path($name)]);
    }

    private function fakeHealthyRegistry(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://repo.packagist.org/p2/vendor/library.json' => Http::response(['packages' => ['vendor/library' => [['version' => '3.0.0']]]]),
            'https://api.osv.dev/v1/querybatch' => Http::response(['results' => [[]]]),
        ]);
    }

    private function workOnce(): void
    {
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'dependencies', '--sleep' => 0])->assertExitCode(0);
        $this->assertDatabaseCount('failed_jobs', 0);
    }
}
