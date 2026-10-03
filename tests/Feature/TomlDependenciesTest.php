<?php

namespace Tests\Feature;

use App\Actions\ReadDependencies;
use App\Actions\ReadTomlDependencies;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class TomlDependenciesTest extends TestCase
{
    public function test_python_lock_selection_and_root_exclusion_keep_ambiguous_direct_versions_incomplete(): void
    {
        Storage::fake('local');
        Storage::put('pyproject.toml', "[project]\nname = \"example\"\ndependencies = [\"requests>=2\"]\n");
        Storage::put('uv.lock', <<<'TOML'
version = 1
[[package]]
name = "example"
version = "0.1.0"
source = { editable = "." }
[[package]]
name = "requests"
version = "2.31.0"
source = { registry = "https://pypi.org/simple" }
[[package]]
name = "requests"
version = "2.32.0"
source = { registry = "https://pypi.org/simple" }
TOML);

        $snapshot = app(ReadDependencies::class)->handle(Storage::path(''));
        [$security, $securitySkipped] = ReadDependencies::additionalPackages($snapshot);
        [$updates, $updatesSkipped] = ReadDependencies::additionalPackages($snapshot, directOnly: true);

        $this->assertSame(['manifest' => 'pyproject.toml', 'lockfile' => 'uv.lock'], $snapshot['additional_ecosystems']['python']);
        $this->assertSame(['2.31.0', '2.32.0'], array_column($security, 'current'));
        $this->assertSame(0, $securitySkipped);
        $this->assertSame([], $updates);
        $this->assertSame(1, $updatesSkipped);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_missing_toml_parser_is_reported_as_unavailable(): void
    {
        $reader = app(ReadTomlDependencies::class);
        $autoloaders = spl_autoload_functions();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Parser unavailable');
        foreach ($autoloaders as $autoloader) {
            spl_autoload_unregister($autoloader);
        }

        try {
            $reader->handle('uv.lock', 'version = 1');
        } finally {
            foreach ($autoloaders as $autoloader) {
                spl_autoload_register($autoloader);
            }
        }
    }

    public function test_python_manifests_preserve_groups_and_requirements_without_claiming_installed_versions(): void
    {
        $contents = <<<'TOML'
[project]
name = "example"
requires-python = ">=3.12"
dependencies = ["Requests[security]>=2.31; python_version >= '3.12'", "pinned==1.2.3"]
[project.optional-dependencies]
web = ["fastapi>=0.100"]
[dependency-groups]
test = ["pytest~=8.0"]
dev = [{ include-group = "test" }, "ruff>=0.5"]
[tool.uv.sources]
requests = { git = "https://example.com/requests.git" }
TOML;

        $source = app(ReadTomlDependencies::class)->handle('pyproject.toml', $contents);

        $this->assertSame(['requests', 'pinned', 'fastapi', 'pytest', 'ruff'], array_column($source['entries'], 'name'));
        $this->assertSame([null, null, null, null, null], array_column($source['entries'], 'version'));
        $this->assertSame(['Production', 'Production', 'Optional', 'Development', 'Development'], array_column($source['entries'], 'scope'));
        $this->assertSame('Custom package source', $source['entries'][0]['link']);
        $this->assertSame(['python' => '>=3.12', 'project-name' => 'example'], $source['requirements']);
    }

    public function test_legacy_poetry_manifests_preserve_constraints_and_source_overrides(): void
    {
        $contents = <<<'TOML'
[tool.poetry]
name = "example"
[tool.poetry.dependencies]
python = "^3.12"
requests = "^2.31"
local = { path = "../local", develop = true }
[tool.poetry.group.test.dependencies]
pytest = { version = "^8.0", optional = true }
TOML;

        $source = app(ReadTomlDependencies::class)->handle('pyproject.toml', $contents);

        $this->assertSame(['requests', 'local', 'pytest'], array_column($source['entries'], 'name'));
        $this->assertSame(['^2.31', '*', '^8.0'], array_column($source['entries'], 'required'));
        $this->assertSame('path:../local', $source['entries'][1]['link']);
        $this->assertSame('Optional', $source['entries'][2]['scope']);
        $this->assertSame(['python' => '^3.12', 'project-name' => 'example'], $source['requirements']);
    }

    public function test_uv_locks_keep_distinct_resolutions_and_mark_root_local_and_private_packages(): void
    {
        $contents = <<<'TOML'
version = 1
revision = 3
requires-python = ">=3.12"
[[package]]
name = "example"
version = "0.1.0"
source = { editable = "." }
[[package]]
name = "requests"
version = "2.31.0"
source = { registry = "https://pypi.org/simple" }
[[package]]
name = "requests"
version = "2.32.0"
source = { registry = "https://pypi.org/simple" }
[[package]]
name = "private"
version = "1!2.0.post1"
source = { registry = "https://private.example/simple" }
[[package]]
name = "workspace"
source = { virtual = "packages/workspace" }
TOML;

        $source = app(ReadTomlDependencies::class)->handle('uv.lock', $contents);

        $this->assertSame(['0.1.0', '2.31.0', '2.32.0', '1!2.0.post1', null], array_column($source['entries'], 'version'));
        $this->assertTrue($source['entries'][0]['root']);
        $this->assertNull($source['entries'][1]['link']);
        $this->assertSame('registry:https://private.example/simple', $source['entries'][3]['link']);
        $this->assertSame('virtual:packages/workspace', $source['entries'][4]['link']);
        $this->assertFalse($source['entries'][4]['root']);
    }

    public function test_poetry_locks_preserve_exact_versions_groups_and_git_sources(): void
    {
        $contents = <<<'TOML'
[[package]]
name = "requests"
version = "2.32.0"
groups = ["main"]
[[package]]
name = "pytest"
version = "8.0.0"
groups = ["test"]
[package.source]
type = "git"
url = "https://example.com/pytest.git"
[[package]]
name = "optional"
version = "2.0.0rc1"
optional = true
[metadata]
lock-version = "2.1"
python-versions = ">=3.12,<4.0"
TOML;

        $source = app(ReadTomlDependencies::class)->handle('poetry.lock', $contents);

        $this->assertSame(['2.32.0', '8.0.0', '2.0.0rc1'], array_column($source['entries'], 'version'));
        $this->assertSame(['Production', 'Development', 'Optional'], array_column($source['entries'], 'scope'));
        $this->assertNull($source['entries'][0]['link']);
        $this->assertSame('url:https://example.com/pytest.git', $source['entries'][1]['link']);
    }

    public function test_cargo_manifests_preserve_aliases_target_dependencies_and_inherited_constraints(): void
    {
        $contents = <<<'TOML'
[package]
name = "example"
rust-version = "1.85"
[dependencies]
serde = "1.0"
renamed = { package = "actual", version = "2.0", optional = true }
local = { path = "../local" }
inherited = { workspace = true }
[workspace.dependencies]
inherited = "3.0"
[dev-dependencies]
test = "0.1"
[target.'cfg(unix)'.dependencies]
libc = "0.2"
TOML;

        $source = app(ReadTomlDependencies::class)->handle('Cargo.toml', $contents);

        $this->assertSame(['serde', 'actual', 'local', 'inherited', 'test', 'libc'], array_column($source['entries'], 'name'));
        $this->assertSame(['1.0', '2.0', '*', '3.0', '0.1', '0.2'], array_column($source['entries'], 'required'));
        $this->assertSame('Optional', $source['entries'][1]['scope']);
        $this->assertSame('path:../local', $source['entries'][2]['link']);
        $this->assertNull($source['entries'][3]['link']);
        $this->assertSame(['rust' => '1.85', 'project-name' => 'example'], $source['requirements']);
    }

    public function test_cargo_locks_keep_exact_duplicate_versions_and_mark_non_registry_sources(): void
    {
        $contents = <<<'TOML'
version = 4
[[package]]
name = "example"
version = "0.1.0"
[[package]]
name = "serde"
version = "1.0.200"
source = "registry+https://github.com/rust-lang/crates.io-index"
[[package]]
name = "serde"
version = "1.0.201"
source = "registry+https://github.com/rust-lang/crates.io-index"
[[package]]
name = "private"
version = "1.0.0"
source = "registry+https://private.example/index"
[[package]]
name = "git"
version = "1.0.0-alpha.1"
source = "git+https://example.com/git#deadbeef"
TOML;

        $source = app(ReadTomlDependencies::class)->handle('Cargo.lock', $contents);

        $this->assertSame(['0.1.0', '1.0.200', '1.0.201', '1.0.0', '1.0.0-alpha.1'], array_column($source['entries'], 'version'));
        $this->assertSame('Local package', $source['entries'][0]['link']);
        $this->assertNull($source['entries'][1]['link']);
        $this->assertSame('registry+https://private.example/index', $source['entries'][3]['link']);
        $this->assertSame('git+https://example.com/git#deadbeef', $source['entries'][4]['link']);
    }

    #[TestWith(['pyproject.toml', '[project', 'Malformed file'])]
    #[TestWith(['pyproject.toml', '[project]\ndependencies = 2', 'Malformed file'])]
    #[TestWith(['pyproject.toml', '[project]\ndynamic = ["dependencies"]', 'Dynamic dependencies unavailable'])]
    #[TestWith(['uv.lock', 'version = 2', 'Unsupported lockfile version'])]
    #[TestWith(['uv.lock', 'version = 1\nrevision = 4', 'Unsupported lockfile version'])]
    #[TestWith(['uv.lock', 'version = 1\npackage = [{ name = "broken", source = {} }]', 'Malformed file'])]
    #[TestWith(['poetry.lock', '[metadata]\nlock-version = "3.0"', 'Unsupported lockfile version'])]
    #[TestWith(['Cargo.lock', 'version = 5', 'Unsupported lockfile version'])]
    #[TestWith(['Cargo.lock', 'version = 4\npackage = [{ name = "broken", version = 42 }]', 'Malformed file'])]
    public function test_malformed_and_unsupported_sources_are_reported(string $file, string $contents, string $message): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        app(ReadTomlDependencies::class)->handle($file, str_replace('\\n', "\n", $contents));
    }
}
