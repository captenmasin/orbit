<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectFolder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DependencyChecksTest extends TestCase
{
    use RefreshDatabase;

    public function test_check_reads_current_files_and_persists_updates_and_security_findings(): void
    {
        Storage::fake('local');
        $folder = $this->folder();
        $root = $folder->packageRoots->first();
        $root->forceFill(['snapshot' => ['runtimes' => ['php' => ['version' => '8.5']]]])->save();
        $this->writePackages($folder, '2.0.0');
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

        $this->postJson("/projects/{$folder->project_id}/roots/{$root->id}/check")->assertExactJson(['ok' => true]);

        $root->refresh();
        $this->assertSame('Current', $root->scan_state);
        $this->assertSame('2.0.0', $root->snapshot['files']['composer.lock']['entries'][0]['version']);
        $this->assertSame('8.5', $root->snapshot['runtimes']['php']['version']);
        $this->assertSame('3.0.0', $root->outdated['packages'][0]['latest']);
        $this->assertSame('High', $root->security['packages'][0]['advisories'][0]['severity']);
        $this->assertSame($root->snapshot['fingerprint'], $root->security['fingerprint']);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.osv.dev/v1/querybatch' && $request['queries'][0]['version'] === '2.0.0');
        $this->get("/projects/{$folder->project_id}")->assertInertia(fn (Assert $page) => $page->where('inspection.0.package_roots.0.security.packages.0.advisories.0.severity', 'High'));
    }

    public function test_returns_404_for_another_projects_location_and_422_for_missing_folder(): void
    {
        $folder = ProjectFolder::factory()->create();
        $root = $folder->packageRoots->first();
        $root->forceFill(['security' => ['packages' => [['name' => 'old/result']]]])->save();
        $other = Project::factory()->create();
        Http::preventStrayRequests();

        $this->postJson("/projects/{$other->id}/roots/{$root->id}/check")->assertNotFound();
        $this->postJson("/projects/{$folder->project_id}/roots/{$root->id}/check")->assertInvalid(['root' => 'Missing folder']);

        $root->refresh();
        $this->assertSame('Stale', $root->scan_state);
        $this->assertSame('old/result', $root->security['packages'][0]['name']);
        Http::assertNothingSent();
    }

    public function test_returns_422_without_publishing_results_when_location_is_edited_during_check(): void
    {
        Storage::fake('local');
        $folder = $this->folder();
        $root = $folder->packageRoots->first();
        $this->writePackages($folder, '2.0.0');
        Http::preventStrayRequests();
        Http::fake([
            'https://repo.packagist.org/p2/vendor/library.json' => function () use ($root) {
                $root->increment('revision');

                return Http::response(['packages' => ['vendor/library' => [['version' => '3.0.0']]]]);
            },
            'https://api.osv.dev/v1/querybatch' => Http::response(['results' => [[]]]),
        ]);

        $this->postJson("/projects/{$folder->project_id}/roots/{$root->id}/check")->assertInvalid(['root' => 'This package location changed. Check it again.']);

        $root->refresh();
        $this->assertSame(2, $root->revision);
        $this->assertNull($root->security);
        $this->assertNull($root->outdated);
        Http::assertSentCount(2);
    }

    public function test_returns_422_and_keeps_changed_package_files_stale(): void
    {
        Storage::fake('local');
        $folder = $this->folder();
        $root = $folder->packageRoots->first();
        $this->writePackages($folder, '2.0.0');
        Http::preventStrayRequests();
        Http::fake([
            'https://repo.packagist.org/p2/vendor/library.json' => function () use ($folder) {
                $this->writePackages($folder, '2.0.1');

                return Http::response(['packages' => ['vendor/library' => [['version' => '3.0.0']]]]);
            },
            'https://api.osv.dev/v1/querybatch' => Http::response(['results' => [[]]]),
        ]);

        $this->postJson("/projects/{$folder->project_id}/roots/{$root->id}/check")->assertInvalid(['root' => 'Package files changed during the check. Check this location again.']);

        $root->refresh();
        $this->assertSame('Stale', $root->scan_state);
        $this->assertSame('2.0.1', $root->snapshot['files']['composer.lock']['entries'][0]['version']);
        $this->assertNull($root->security);
        $this->assertNull($root->outdated);
        Http::assertSentCount(2);
    }

    public function test_relinking_a_folder_clears_its_security_results(): void
    {
        $folder = ProjectFolder::factory()->create();
        $root = $folder->packageRoots->first();
        $root->forceFill(['security' => ['packages' => [['name' => 'old/result']]]])->save();

        $folder->update(['path' => '/different/folder']);

        $this->assertNull($root->fresh()->security);
    }

    public function test_returns_422_without_overwriting_a_newer_check_of_the_same_files(): void
    {
        Storage::fake('local');
        $folder = $this->folder();
        $root = $folder->packageRoots->first();
        $this->writePackages($folder, '2.0.0');
        $newToken = (string) Str::uuid();
        Http::preventStrayRequests();
        Http::fake([
            'https://repo.packagist.org/p2/vendor/library.json' => function () use ($root, $newToken) {
                $root->forceFill(['scan_token' => $newToken, 'security' => ['unavailable' => 1]])->save();

                return Http::response(['packages' => ['vendor/library' => [['version' => '3.0.0']]]]);
            },
            'https://api.osv.dev/v1/querybatch' => Http::response(['results' => [[]]]),
        ]);

        $this->postJson("/projects/{$folder->project_id}/roots/{$root->id}/check")->assertInvalid(['root' => 'This package location changed. Check it again.']);

        $root->refresh();
        $this->assertSame($newToken, $root->scan_token);
        $this->assertSame(1, $root->security['unavailable']);
        $this->assertNull($root->outdated);
        Http::assertSentCount(2);
    }

    public function test_returns_422_when_a_package_location_symlink_switches_during_check(): void
    {
        Storage::fake('local');
        $folder = $this->folder();
        $root = $folder->packageRoots->first();
        Storage::makeDirectory('project/first');
        Storage::makeDirectory('project/second');
        foreach (['first', 'second'] as $directory) {
            $this->writePackages(new ProjectFolder(['path' => $folder->path.'/'.$directory]), '2.0.0');
        }
        symlink($folder->path.'/first', $folder->path.'/packages');
        $root->forceFill(['relative_path' => 'packages'])->save();
        Http::preventStrayRequests();
        Http::fake([
            'https://repo.packagist.org/p2/vendor/library.json' => function () use ($folder) {
                unlink($folder->path.'/packages');
                symlink($folder->path.'/second', $folder->path.'/packages');

                return Http::response(['packages' => ['vendor/library' => [['version' => '3.0.0']]]]);
            },
            'https://api.osv.dev/v1/querybatch' => Http::response(['results' => [[]]]),
        ]);

        $this->postJson("/projects/{$folder->project_id}/roots/{$root->id}/check")->assertInvalid(['root' => 'This package location changed. Check it again.']);

        $root->refresh();
        $this->assertSame('Stale', $root->scan_state);
        $this->assertNull($root->security);
        $this->assertNull($root->outdated);
        Http::assertSentCount(2);
    }

    private function folder(): ProjectFolder
    {
        Storage::makeDirectory('project');

        return ProjectFolder::factory()->create(['path' => Storage::path('project')]);
    }

    private function writePackages(ProjectFolder $folder, string $version): void
    {
        file_put_contents($folder->path.'/composer.json', json_encode(['require' => ['vendor/library' => '^2.0']], JSON_THROW_ON_ERROR));
        file_put_contents($folder->path.'/composer.lock', json_encode(['packages' => [['name' => 'vendor/library', 'version' => $version]]], JSON_THROW_ON_ERROR));

    }
}
