<?php

namespace Tests\Feature;

use App\Actions\ReadDependencies;
use App\Models\ProjectFolder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class DependencyUpdatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_checks_locked_direct_packages_and_keeps_ecosystems_separate(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://repo.packagist.org/p2/laravel/framework.json' => Http::response(['packages' => ['laravel/framework' => [['version' => 'v13.4.0'], ['version' => 'v14.0.0-beta1'], ['version' => 'v13.3.0']]]]),
            'https://registry.npmjs.org/vue/latest' => Http::response(['version' => '3.5.2']),
            'https://registry.npmjs.org/%40example%2Fui/latest' => Http::response(['version' => '1.0.0']),
        ]);
        $folder = ProjectFolder::factory()->create();
        $root = $folder->packageRoots()->sole();
        $snapshot = $this->snapshot();
        $root->forceFill(['scan_state' => 'Current', 'snapshot' => $snapshot])->save();

        $this->postJson('/projects/'.$folder->project_id.'/roots/'.$root->id.'/outdated')->assertJsonPath('checked', true);

        $result = $root->fresh()->outdated;
        $this->assertSame(3, $result['checked']);
        $this->assertSame(2, $result['skipped']);
        $this->assertSame(0, $result['unavailable']);
        $this->assertSame([
            ['name' => 'laravel/framework', 'ecosystem' => 'composer', 'current' => 'v13.2.0', 'latest' => '13.4.0'],
            ['name' => 'vue', 'ecosystem' => 'npm', 'current' => '3.5.1', 'latest' => '3.5.2'],
        ], $result['packages']);
        Http::assertSentCount(3);
        $this->get('/projects/'.$folder->project_id)->assertInertia(fn (Assert $page) => $page->has('inspection.0.package_roots.0.outdated.packages', 2));
        $this->assertSame($snapshot, $root->fresh()->snapshot);
    }

    public function test_failed_registries_are_reported_as_unavailable_instead_of_up_to_date(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://repo.packagist.org/p2/laravel/framework.json' => Http::response([], 503),
            'https://registry.npmjs.org/vue/latest' => Http::failedConnection(),
            'https://registry.npmjs.org/%40example%2Fui/latest' => Http::response(['version' => 'invalid']),
        ]);
        $folder = ProjectFolder::factory()->create();
        $root = $folder->packageRoots()->sole();
        $root->forceFill(['scan_state' => 'Current', 'snapshot' => $this->snapshot()])->save();

        $this->postJson('/projects/'.$folder->project_id.'/roots/'.$root->id.'/outdated')->assertOk();
        $this->assertSame(3, $root->fresh()->outdated['unavailable']);
        $this->assertSame(0, $root->fresh()->outdated['checked']);
        $this->assertSame([], $root->fresh()->outdated['packages']);
    }

    public function test_rejects_foreign_and_unscanned_roots_without_registry_requests(): void
    {
        Http::preventStrayRequests();
        $folder = ProjectFolder::factory()->create();
        $other = ProjectFolder::factory()->create();
        $root = $folder->packageRoots()->sole();
        $this->postJson('/projects/'.$other->project_id.'/roots/'.$root->id.'/outdated')->assertNotFound();
        $this->postJson('/projects/'.$folder->project_id.'/roots/'.$root->id.'/outdated')->assertUnprocessable()->assertJsonValidationErrors('root');
        $this->assertNull($root->fresh()->outdated);
        Http::assertNothingSent();
    }

    public function test_scan_fingerprints_ignore_timestamps_but_change_with_dependency_data(): void
    {
        $snapshot = $this->snapshot();
        $fingerprint = ReadDependencies::fingerprint($snapshot);
        $snapshot['files']['package.json']['scanned_at'] = '2026-09-21T12:00:00Z';
        $this->assertSame($fingerprint, ReadDependencies::fingerprint($snapshot));
        $snapshot['files']['package-lock.json']['entries'][0]['version'] = '3.5.2';
        $this->assertNotSame($fingerprint, ReadDependencies::fingerprint($snapshot));
    }

    #[TestWith(['pnpm-lock.yaml', "lockfileVersion: '9.0'\nimporters:\n  .:\n    dependencies:\n      vue:\n        specifier: ^3.5\n        version: 3.5.1(typescript@5.9.3)\n  apps/admin:\n    dependencies:\n      vue:\n        specifier: ^3.4\n        version: 3.4.2\npackages:\n  vue@3.5.1: {}\n  vue@3.4.2: {}\n"])]
    #[TestWith(['yarn.lock', "__metadata:\n  version: 8\n\"vue@npm:^3.5\":\n  version: 3.5.1\n  resolution: \"vue@npm:3.5.1\"\n\"vue@npm:^3.4\":\n  version: 3.4.2\n  resolution: \"vue@npm:3.4.2\"\n"])]
    #[TestWith(['bun.lock', '{"lockfileVersion":2,"workspaces":{"":{"dependencies":{"vue":"^3.5"}}},"packages":{"vue":["vue@3.5.1","",{},"integrity"],"admin/vue":["vue@3.4.2","",{},"integrity"]}}'])]
    public function test_checks_direct_versions_from_the_selected_lockfile(string $lockfile, string $contents): void
    {
        Storage::fake('local');
        Storage::put('package.json', '{"dependencies":{"vue":"^3.5"}}');
        Storage::put($lockfile, $contents);
        Http::preventStrayRequests();
        Http::fake(['https://registry.npmjs.org/vue/latest' => Http::response(['version' => '3.5.2'])]);
        $folder = ProjectFolder::factory()->create();
        $root = $folder->packageRoots()->sole();
        $root->forceFill(['scan_state' => 'Current', 'snapshot' => app(ReadDependencies::class)->handle(Storage::path(''))])->save();

        $this->postJson('/projects/'.$folder->project_id.'/roots/'.$root->id.'/outdated')->assertJsonPath('checked', true);

        $this->assertSame([
            ['name' => 'vue', 'ecosystem' => 'npm', 'current' => '3.5.1', 'latest' => '3.5.2'],
        ], $root->fresh()->outdated['packages']);
        $this->assertSame(1, $root->fresh()->outdated['checked']);
        Http::assertSentCount(1);
    }

    #[TestWith(['pnpm@10.12.1', 'pnpm-lock.yaml', "lockfileVersion: '10.0'\n"])]
    #[TestWith(['yarn@1.22.22', 'yarn.lock', "# yarn lockfile v1\n"])]
    #[TestWith(['bun@1.1.0', 'bun.lockb', "bun-lockfile-format-v0\0binary"])]
    public function test_does_not_fall_back_to_another_managers_lock_when_selected_lock_is_unsupported(string $manager, string $lockfile, string $contents): void
    {
        Storage::fake('local');
        Storage::put('package.json', json_encode(['dependencies' => ['vue' => '^3.5'], 'packageManager' => $manager]));
        Storage::put('package-lock.json', '{"lockfileVersion":3,"packages":{"node_modules/vue":{"version":"3.5.1"}}}');
        Storage::put($lockfile, $contents);
        Http::preventStrayRequests();
        $folder = ProjectFolder::factory()->create();
        $root = $folder->packageRoots()->sole();
        $root->forceFill(['scan_state' => 'Partial', 'snapshot' => app(ReadDependencies::class)->handle(Storage::path(''))])->save();

        $this->postJson('/projects/'.$folder->project_id.'/roots/'.$root->id.'/outdated')->assertOk();

        $this->assertSame(0, $root->fresh()->outdated['checked']);
        $this->assertSame(1, $root->fresh()->outdated['skipped']);
        Http::assertNothingSent();
    }

    public function test_ambiguous_lock_selection_skips_registry_checks(): void
    {
        Http::preventStrayRequests();
        $folder = ProjectFolder::factory()->create();
        $root = $folder->packageRoots()->sole();
        $snapshot = ['files' => [
            'package.json' => ['state' => 'Current', 'entries' => [['name' => 'vue', 'required' => '^3.5']]],
            'package-lock.json' => ['state' => 'Current', 'entries' => [['name' => 'vue', 'version' => '3.5.1', 'location' => 'node_modules/vue']]],
        ], 'npm_lockfile' => null];
        $root->forceFill(['scan_state' => 'Current', 'snapshot' => $snapshot])->save();

        $this->postJson('/projects/'.$folder->project_id.'/roots/'.$root->id.'/outdated')->assertOk();

        $this->assertSame(0, $root->fresh()->outdated['checked']);
        $this->assertSame(1, $root->fresh()->outdated['skipped']);
        Http::assertNothingSent();
    }

    public function test_workspace_roots_without_a_local_lock_do_not_borrow_versions_from_the_parent(): void
    {
        Storage::fake('local');
        Storage::put('package-lock.json', '{"lockfileVersion":3,"packages":{"node_modules/vue":{"version":"3.5.1"}}}');
        Storage::put('apps/admin/package.json', '{"dependencies":{"vue":"^3.4"}}');
        Http::preventStrayRequests();
        $folder = ProjectFolder::factory()->create();
        $root = $folder->packageRoots()->sole();
        $root->forceFill(['scan_state' => 'Current', 'snapshot' => app(ReadDependencies::class)->handle(Storage::path('apps/admin'))])->save();

        $this->postJson('/projects/'.$folder->project_id.'/roots/'.$root->id.'/outdated')->assertOk();

        $this->assertSame(0, $root->fresh()->outdated['checked']);
        $this->assertSame(1, $root->fresh()->outdated['skipped']);
        Http::assertNothingSent();
    }

    private function snapshot(): array
    {
        return ['version' => 1, 'files' => [
            'composer.json' => ['state' => 'Current', 'entries' => [['name' => 'php', 'required' => '^8.5'], ['name' => 'laravel/framework', 'required' => '^13.0']]],
            'composer.lock' => ['state' => 'Current', 'entries' => [['name' => 'laravel/framework', 'version' => 'v13.2.0'], ['name' => 'example/transitive', 'version' => '1.0.0']]],
            'package.json' => ['state' => 'Current', 'entries' => [
                ['name' => 'vue', 'required' => '^3.5'], ['name' => '@example/ui', 'required' => '^1.0'],
                ['name' => 'local', 'required' => 'file:../local'], ['name' => 'unlocked', 'required' => '^1.0'],
            ]],
            'package-lock.json' => ['state' => 'Current', 'entries' => [
                ['name' => 'vue', 'version' => '3.5.1', 'location' => 'node_modules/vue'],
                ['name' => '@example/ui', 'version' => '1.0.0', 'location' => 'node_modules/@example/ui'],
                ['name' => 'local', 'version' => '1.0.0', 'location' => 'node_modules/local'],
            ]],
        ]];
    }
}
