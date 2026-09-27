<?php

namespace Tests\Feature;

use App\Actions\ReadDependencies;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
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
        Storage::put('pnpm-lock.yaml', "lockfileVersion: '10.0'\n");

        $result = app(ReadDependencies::class)->handle(Storage::path(''));

        $this->assertSame(['^8.5', '*', '^13', '^12'], array_column($result['files']['composer.json']['entries'], 'required'));
        $this->assertSame(['v13.31.0', '12.5.35'], array_column($result['files']['composer.lock']['entries'], 'version'));
        $this->assertSame('Development', $result['files']['composer.lock']['entries'][1]['scope']);
        $this->assertSame(['Production', 'Development', 'Peer', 'Optional'], array_column($result['files']['package.json']['entries'], 'scope'));
        $this->assertSame(['node' => '>=22', 'packageManager' => 'pnpm@10.12.1'], $result['files']['package.json']['requirements']);
        $this->assertSame(['pnpm-lock.yaml'], $result['unsupported_lockfiles']);
        $this->assertSame('Missing file', $result['files']['package-lock.json']['state']);
        $this->assertSame('pnpm-lock.yaml', $result['npm_lockfile']);
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
    #[TestWith(['pnpm-lock.yaml', "lockfileVersion: '10.0'\n", 'Unsupported lockfile version'])]
    #[TestWith(['pnpm-lock.yaml', "lockfileVersion: '9.0'\nimporters: [broken\n", 'Malformed file'])]
    #[TestWith(['pnpm-lock.yaml', "lockfileVersion: '9.0'\nimporters: &alias {}\npackages: *alias\n", 'Malformed file'])]
    #[TestWith(['yarn.lock', "# yarn lockfile v1\nvue@^3:\n  version \"3.5.1\"\n", 'Unsupported lockfile format'])]
    #[TestWith(['bun.lock', '{"lockfileVersion":3,"workspaces":{"":{}},"packages":{}}', 'Unsupported lockfile version'])]
    #[TestWith(['bun.lock', '{"lockfileVersion":2,"workspaces":{"":{}},"packages":{"vue":[]}}', 'Malformed file'])]
    #[TestWith(['bun.lock', '{"lockfileVersion":2,"workspaces":{"":{}},"packages":{},"value":1/*comment*/2}', 'Malformed file'])]
    #[TestWith(['bun.lockb', "bun-lockfile-format-v0\0binary", 'Unsupported lockfile format'])]
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

    public function test_pnpm_importers_preserve_distinct_versions_peer_suffixes_and_workspace_links(): void
    {
        Storage::fake('local');
        Storage::put('package.json', '{"dependencies":{"vue":"^3.5"},"packageManager":"pnpm@10.12.1"}');
        Storage::put('pnpm-lock.yaml', <<<'YAML'
lockfileVersion: '9.0'
importers:
  .:
    dependencies:
      vue:
        specifier: ^3.5
        version: 3.5.1(typescript@5.9.3)
      local:
        specifier: workspace:*
        version: link:packages/local
  apps/admin:
    devDependencies:
      vue:
        specifier: ^3.4
        version: 3.4.2
packages:
  vue@3.5.1: {}
  vue@3.4.2: {}
  '@scope/transitive@1.0.0': {}
YAML);

        $result = app(ReadDependencies::class)->handle(Storage::path(''));
        $source = $result['files']['pnpm-lock.yaml'];

        $this->assertSame('Current', $source['state']);
        $this->assertSame([], $result['unsupported_lockfiles']);
        $this->assertSame('3.5.1', $source['entries'][0]['version']);
        $this->assertSame('node_modules/vue', $source['entries'][0]['location']);
        $this->assertNull($source['entries'][1]['version']);
        $this->assertSame('link:packages/local', $source['entries'][1]['link']);
        $this->assertSame('3.4.2', $source['entries'][2]['version']);
        $this->assertSame('apps/admin/node_modules/vue', $source['entries'][2]['location']);
        $this->assertSame('Development', $source['entries'][2]['scope']);
        $this->assertSame('@scope/transitive', $source['entries'][5]['name']);
    }

    public function test_yarn_berry_maps_matching_descriptors_and_keeps_transitive_versions_and_workspace_links(): void
    {
        Storage::fake('local');
        Storage::put('package.json', '{"dependencies":{"vue":"^3.5","@scope/ui":"^1","local":"workspace:*"},"packageManager":"yarn@4.0.0"}');
        Storage::put('yarn.lock', <<<'YAML'
__metadata:
  version: 8
"vue@npm:^3.5, vue@npm:~3.5.1":
  version: 3.5.1
  resolution: "vue@npm:3.5.1"
"vue@npm:^3.4":
  version: 3.4.2
  resolution: "vue@npm:3.4.2"
"@scope/ui@npm:^1":
  version: 1.0.0
  resolution: "@scope/ui@npm:1.0.0"
"local@workspace:*":
  version: 0.0.0-use.local
  resolution: "local@workspace:packages/local"
YAML);

        $result = app(ReadDependencies::class)->handle(Storage::path(''));
        $source = $result['files']['yarn.lock'];

        $this->assertSame('yarn.lock', $result['npm_lockfile']);
        $this->assertSame('Current', $source['state']);
        $this->assertSame('node_modules/vue', $source['entries'][1]['location']);
        $this->assertSame('3.5.1', $source['entries'][1]['version']);
        $this->assertSame('3.4.2', $source['entries'][2]['version']);
        $this->assertSame('node_modules/@scope/ui', $source['entries'][4]['location']);
        $this->assertSame('workspace:packages/local', $source['entries'][6]['link']);
        $this->assertNull($source['entries'][6]['version']);
    }

    public function test_multiple_lockfiles_are_ambiguous_without_a_declared_manager(): void
    {
        Storage::fake('local');
        Storage::put('package-lock.json', '{"lockfileVersion":3,"packages":{}}');
        Storage::put('yarn.lock', '# yarn lockfile v1');

        $this->assertNull(app(ReadDependencies::class)->handle(Storage::path(''))['npm_lockfile']);
    }

    #[TestWith([0])]
    #[TestWith([1])]
    #[TestWith([2])]
    public function test_bun_text_locks_preserve_jsonc_strings_scoped_packages_nested_versions_and_local_links(int $version): void
    {
        Storage::fake('local');
        Storage::put('package.json', '{"dependencies":{"vue":"^3.5"},"packageManager":"bun@1.4.2"}');
        Storage::put('bun.lock', str_replace('LOCK_VERSION', (string) $version, <<<'JSON'
{
  // Bun's lockfile permits comments and trailing commas.
  "lockfileVersion": LOCK_VERSION,
  "workspaces": {
    "": { "dependencies": { "vue": "^3.5", "local": "workspace:*", }, "devDependencies": { "@scope/ui": "^1", }, },
    "apps/admin": { "name": "admin", "dependencies": { "vue": "^3.4", }, },
  },
  /* Registry URLs and comment-like content must remain strings. */
  "packages": {
    "vue": ["vue@3.5.1", "https://registry.npmjs.org", {}, "sha512-//*/},"],
    "admin/vue": ["vue@3.4.2", "", {}, "sha512-placeholder",],
    "@scope/ui": ["@scope/ui@1.0.0", "", {}, "sha512-placeholder"],
    "local": ["local@workspace:apps/local"],
    "transitive": ["transitive@2.0.0", "", {}, "sha512-placeholder"],
  },
}
JSON));
        Storage::put('bun.lockb', 'legacy ignored when text lock exists');

        $result = app(ReadDependencies::class)->handle(Storage::path(''));
        $source = $result['files']['bun.lock'];

        $this->assertSame('bun.lock', $result['npm_lockfile']);
        $this->assertSame([], $result['unsupported_lockfiles']);
        $this->assertSame('Current', $source['state']);
        $this->assertSame($version, $source['lockfile_version']);
        $this->assertSame(['3.5.1', '3.4.2', '1.0.0', null, '2.0.0'], array_column($source['entries'], 'version'));
        $this->assertSame('node_modules/vue', $source['entries'][0]['location']);
        $this->assertSame('lock:admin/vue', $source['entries'][1]['location']);
        $this->assertSame('@scope/ui', $source['entries'][2]['name']);
        $this->assertSame('Development', $source['entries'][2]['scope']);
        $this->assertSame('workspace:apps/local', $source['entries'][3]['link']);
    }

    public function test_bun_binary_locks_are_selected_and_reported_as_unsupported_without_a_text_lock(): void
    {
        Storage::fake('local');
        Storage::put('package.json', '{"dependencies":{"vue":"^3.5"},"packageManager":"bun@1.1.0"}');
        Storage::put('bun.lockb', "bun-lockfile-format-v0\0binary");

        $result = app(ReadDependencies::class)->handle(Storage::path(''));

        $this->assertSame('bun.lockb', $result['npm_lockfile']);
        $this->assertSame('Unsupported lockfile format', $result['files']['bun.lockb']['state']);
        $this->assertSame(['bun.lockb'], $result['unsupported_lockfiles']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_missing_yaml_parser_is_reported_as_incomplete(): void
    {
        Storage::fake('local');
        Storage::put('pnpm-lock.yaml', "lockfileVersion: '9.0'\nimporters: {}\n");
        $reader = app(ReadDependencies::class);
        $path = Storage::path('');
        $this->freezeTime();
        now()->toIso8601String();
        $autoloaders = spl_autoload_functions();
        foreach ($autoloaders as $autoloader) {
            spl_autoload_unregister($autoloader);
        }
        try {
            $result = $reader->handle($path);
        } finally {
            foreach ($autoloaders as $autoloader) {
                spl_autoload_register($autoloader);
            }
        }

        $this->assertSame('Parser unavailable', $result['files']['pnpm-lock.yaml']['state']);
        $this->assertSame([], $result['files']['pnpm-lock.yaml']['entries']);
        $this->assertSame(['pnpm-lock.yaml'], $result['unsupported_lockfiles']);
    }
}
