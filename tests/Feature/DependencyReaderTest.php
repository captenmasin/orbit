<?php

namespace Tests\Feature;

use App\Actions\ReadDependencies;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class DependencyReaderTest extends TestCase
{
    public function test_mixed_roots_preserve_constraints_scopes_engines_and_exact_lock_versions(): void
    {
        Storage::fake('local');
        Storage::put('composer.json', json_encode(['require' => ['php' => '^8.5', 'ext-json' => '*', 'laravel/framework' => '^13'], 'require-dev' => ['phpunit/phpunit' => '^12']]));
        Storage::put('composer.lock', json_encode(['packages' => [['name' => 'laravel/framework', 'version' => 'v13.31.0']], 'packages-dev' => [['name' => 'phpunit/phpunit', 'version' => '12.5.35']]]));
        Storage::put('package.json', json_encode(['dependencies' => ['vue' => '^3'], 'devDependencies' => ['vite' => '^8'], 'peerDependencies' => ['react' => '>=18'], 'optionalDependencies' => ['fsevents' => '~2'], 'engines' => ['node' => '>=22'], 'packageManager' => 'pnpm@10.12.1']));
        Storage::put('pnpm-lock.yaml', 'unsupported: true');

        $result = app(ReadDependencies::class)->handle(Storage::path(''));

        $this->assertSame(['^8.5', '*', '^13', '^12'], array_column($result['files']['composer.json']['entries'], 'required'));
        $this->assertSame(['v13.31.0', '12.5.35'], array_column($result['files']['composer.lock']['entries'], 'version'));
        $this->assertSame('Development', $result['files']['composer.lock']['entries'][1]['scope']);
        $this->assertSame(['Production', 'Development', 'Peer', 'Optional'], array_column($result['files']['package.json']['entries'], 'scope'));
        $this->assertSame(['node' => '>=22', 'packageManager' => 'pnpm@10.12.1'], $result['files']['package.json']['requirements']);
        $this->assertSame(['pnpm-lock.yaml'], $result['unsupported_lockfiles']);
        $this->assertSame('Missing file', $result['files']['package-lock.json']['state']);
    }

    #[TestWith([1])]
    #[TestWith([2])]
    #[TestWith([3])]
    public function test_npm_lock_versions_preserve_nested_duplicates_and_workspace_links(int $version): void
    {
        Storage::fake('local');
        $data = $version === 1 ? ['dependencies' => [
            'shared' => ['version' => '1.0.0'], 'parent' => ['version' => '2.0.0', 'dependencies' => ['shared' => ['version' => '3.0.0', 'dev' => true]]],
        ]] : ['packages' => [
            '' => ['name' => 'root', 'version' => '9.0.0'],
            'node_modules/shared' => ['version' => '1.0.0'], 'node_modules/parent' => ['version' => '2.0.0'],
            'node_modules/parent/node_modules/shared' => ['version' => '3.0.0', 'dev' => true],
            'node_modules/@scope/workspace' => ['link' => true, 'resolved' => 'packages/workspace'],
        ]];
        Storage::put('package-lock.json', json_encode(['lockfileVersion' => $version, ...$data]));

        $source = app(ReadDependencies::class)->handle(Storage::path(''))['files']['package-lock.json'];

        $this->assertSame('Current', $source['state']);
        $this->assertSame($version, $source['lockfile_version']);
        $this->assertSame(['1.0.0', '3.0.0'], array_column(array_values(array_filter($source['entries'], fn ($entry) => $entry['name'] === 'shared')), 'version'));
        $this->assertSame('node_modules/parent/node_modules/shared', $source['entries'][2]['location']);
        if ($version !== 1) {
            $this->assertCount(4, $source['entries']);
            $this->assertSame('@scope/workspace', $source['entries'][3]['name']);
            $this->assertSame('packages/workspace', $source['entries'][3]['link']);
            $this->assertNull($source['entries'][3]['version']);
        }
    }

    #[TestWith(['package.json', '{broken', 'Malformed file'])]
    #[TestWith(['package.json', '{"dependencies":{"vue":3}}', 'Malformed file'])]
    #[TestWith(['package.json', '{"dependencies":[]}', 'Malformed file'])]
    #[TestWith(['composer.lock', '{}', 'Malformed file'])]
    #[TestWith(['package-lock.json', '{"lockfileVersion":4}', 'Unsupported lockfile version'])]
    #[TestWith(['package-lock.json', '{"lockfileVersion":3,"packages":{"node_modules/a":{"dev":"false"}}}', 'Malformed file'])]
    public function test_invalid_sources_keep_their_previous_entries_and_timestamp(string $file, string $contents, string $state): void
    {
        Storage::fake('local');
        Storage::put($file, $contents);
        $previous = ['files' => [$file => ['file' => $file, 'entries' => [['name' => 'previous']], 'scanned_at' => '2026-01-01T00:00:00+00:00']]];

        $source = app(ReadDependencies::class)->handle(Storage::path(''), $previous)['files'][$file];

        $this->assertSame($state, $source['state']);
        $this->assertSame([['name' => 'previous']], $source['entries']);
        $this->assertSame('2026-01-01T00:00:00+00:00', $source['scanned_at']);
    }

    public function test_source_limits_and_symlinks_are_bounded_without_following_external_files(): void
    {
        Storage::fake('local');
        Storage::makeDirectory('root');
        Storage::put('root/composer.json', str_repeat(' ', ReadDependencies::MANIFEST_LIMIT + 1));
        Storage::put('root/composer.lock', str_repeat(' ', ReadDependencies::LOCK_LIMIT + 1));
        Storage::put('outside.json', '{"dependencies":{"outside":"secret"}}');
        symlink(Storage::path('outside.json'), Storage::path('root/package.json'));
        Storage::put('root/package-lock.json', str_repeat('{"a":', 70).'{}'.str_repeat('}', 70));

        $files = app(ReadDependencies::class)->handle(Storage::path('root'))['files'];

        $this->assertSame('File too large', $files['composer.json']['state']);
        $this->assertSame('File too large', $files['composer.lock']['state']);
        $this->assertSame('Source is outside the package root', $files['package.json']['state']);
        $this->assertSame([], $files['package.json']['entries']);
        $this->assertSame('Malformed file', $files['package-lock.json']['state']);
    }

    public function test_entry_limit_and_removed_sources_preserve_useful_previous_data(): void
    {
        Storage::fake('local');
        $packages = array_fill(0, ReadDependencies::ENTRY_LIMIT + 1, ['name' => 'package', 'version' => '1.0']);
        Storage::put('composer.lock', json_encode(['packages' => $packages]));
        $previous = ['files' => ['package.json' => ['file' => 'package.json', 'entries' => [['name' => 'vue', 'required' => '^3']], 'scanned_at' => '2026-01-01T00:00:00Z']]];

        $files = app(ReadDependencies::class)->handle(Storage::path(''), $previous)['files'];

        $this->assertSame('Too many entries', $files['composer.lock']['state']);
        $this->assertSame('Missing file', $files['package.json']['state']);
        $this->assertSame('vue', $files['package.json']['entries'][0]['name']);
        $this->assertSame('2026-01-01T00:00:00Z', $files['package.json']['scanned_at']);
    }
}
