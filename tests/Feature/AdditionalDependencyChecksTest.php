<?php

namespace Tests\Feature;

use App\Actions\CheckDependencySecurity;
use App\Actions\CheckDependencyUpdates;
use App\Actions\ReadDependencies;
use App\DependencyVersions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AdditionalDependencyChecksTest extends TestCase
{
    #[DataProvider('ecosystems')]
    public function test_checks_updates_and_security_for_each_additional_ecosystem(string $ecosystem, string $file, string $contents, string $name, string $current, string $latest, string $registry, array $payload): void
    {
        Storage::fake('local');
        Storage::put($file, $contents);
        Http::preventStrayRequests();
        Http::fake([
            $registry => Http::response($payload),
            'https://api.osv.dev/v1/querybatch' => Http::response(['results' => [(object) []]]),
        ]);
        $snapshot = app(ReadDependencies::class)->handle(Storage::path(''));

        $updates = app(CheckDependencyUpdates::class)->handle($snapshot);
        $security = app(CheckDependencySecurity::class)->handle($snapshot);

        $this->assertSame('Current', $snapshot['files'][$file]['state']);
        $this->assertArrayHasKey($ecosystem, $snapshot['additional_ecosystems']);
        $this->assertSame([['name' => $name, 'ecosystem' => $ecosystem, 'current' => $current, 'latest' => $latest]], $updates['packages']);
        $this->assertSame([1, 0, 0], [$updates['checked'], $updates['unavailable'], $updates['skipped']]);
        $this->assertSame($file === 'pom.xml' ? [1, 0, 1] : [1, 0, 0], [$security['checked'], $security['unavailable'], $security['skipped']]);
        $this->assertSame($file === 'pom.xml' ? 1 : 0, $security['incomplete']);
        $this->assertSame(0, $updates['incomplete']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.osv.dev/v1/querybatch'
            && $request['queries'] === [['package' => ['name' => $name, 'ecosystem' => DependencyVersions::OSV[$ecosystem]], 'version' => $ecosystem === 'go' ? $current : ltrim($current, 'v')]]);
        Http::assertSentCount(2);
    }

    public static function ecosystems(): array
    {
        return [
            'python' => ['python', 'requirements.txt', "Requests==2.31.0\n", 'requests', '2.31.0', '2.32.0', 'https://pypi.org/pypi/requests/json', ['info' => ['version' => '2.32.0']]],
            'rust' => ['rust', 'Cargo.lock', "version = 4\n[[package]]\nname = \"serde\"\nversion = \"1.0.1\"\nsource = \"registry+https://github.com/rust-lang/crates.io-index\"\n", 'serde', '1.0.1', '1.0.2', 'https://crates.io/api/v1/crates/serde', ['crate' => ['max_stable_version' => '1.0.2']]],
            'go' => ['go', 'go.mod', "module example.com/app\ngo 1.23.0\nrequire example.com/lib v1.0.0\n", 'example.com/lib', 'v1.0.0', '1.1.0', 'https://proxy.golang.org/example.com/lib/@latest', ['Version' => 'v1.1.0']],
            'ruby' => ['ruby', 'Gemfile.lock', "GEM\n  remote: https://rubygems.org/\n  specs:\n    rake (13.0.0)\n\nPLATFORMS\n  ruby\n\nDEPENDENCIES\n  rake\n\nBUNDLED WITH\n   2.5.0\n", 'rake', '13.0.0', '13.1.0', 'https://rubygems.org/api/v1/gems/rake.json', ['version' => '13.1.0']],
            'nuget' => ['nuget', 'packages.lock.json', '{"version":1,"dependencies":{"net8.0":{"Newtonsoft.Json":{"type":"Direct","requested":"[13.0.1,)","resolved":"13.0.1"}}}}', 'newtonsoft.json', '13.0.1', '13.0.3', 'https://api.nuget.org/v3-flatcontainer/newtonsoft.json/index.json', ['versions' => ['13.0.2-beta', '13.0.3']]],
            'dart' => ['dart', 'pubspec.lock', "packages:\n  async:\n    dependency: direct main\n    description:\n      name: async\n      url: https://pub.dev\n    source: hosted\n    version: '2.11.0'\n", 'async', '2.11.0', '2.12.0', 'https://pub.dev/api/packages/async', ['latest' => ['version' => '2.12.0']]],
            'maven' => ['maven', 'pom.xml', '<project><modelVersion>4.0.0</modelVersion><dependencies><dependency><groupId>org.example</groupId><artifactId>library</artifactId><version>1.0.0</version></dependency></dependencies></project>', 'org.example:library', '1.0.0', '1.1.0', 'https://search.maven.org/solrsearch/select*', ['response' => ['docs' => [['latestVersion' => '1.1.0']]]]],
            'gradle' => ['maven', 'gradle.lockfile', "# Gradle lockfile\norg.example:library:1.0.0=runtimeClasspath\nempty=\n", 'org.example:library', '1.0.0', '1.1.0', 'https://search.maven.org/solrsearch/select*', ['response' => ['docs' => [['latestVersion' => '1.1.0']]]]],
        ];
    }

    public function test_ranges_local_dependencies_and_failed_sources_remain_incomplete(): void
    {
        Storage::fake('local');
        Storage::put('requirements.txt', "requests>=2.0\nlocal @ file:../local\n");
        Storage::put('Cargo.lock', 'broken');
        Http::preventStrayRequests();
        $snapshot = app(ReadDependencies::class)->handle(Storage::path(''));

        $updates = app(CheckDependencyUpdates::class)->handle($snapshot);
        $security = app(CheckDependencySecurity::class)->handle($snapshot);

        $this->assertSame([0, 3], [$updates['checked'], $updates['skipped']]);
        $this->assertSame([0, 3], [$security['checked'], $security['skipped']]);
        Http::assertNothingSent();
    }

    public function test_oversized_or_malformed_registry_responses_are_unavailable(): void
    {
        Storage::fake('local');
        Storage::put('requirements.txt', "requests==2.31.0\nflask==3.0.0\n");
        Http::preventStrayRequests();
        Http::fake([
            'https://pypi.org/pypi/requests/json' => Http::response('{"info":{"version":"2.32.0"},"padding":"'.str_repeat('a', 4194304).'"}'),
            'https://pypi.org/pypi/flask/json' => Http::response('{broken'),
        ]);

        $updates = app(CheckDependencyUpdates::class)->handle(app(ReadDependencies::class)->handle(Storage::path('')));

        $this->assertSame([0, 2, []], [$updates['checked'], $updates['unavailable'], $updates['packages']]);
        Http::assertSentCount(2);
    }

    public function test_additional_packages_above_the_check_limit_remain_incomplete(): void
    {
        Storage::fake('local');
        Storage::put('requirements.txt', implode("\n", array_map(fn (int $number): string => 'package'.$number.'==1.0.0', range(1, 502))));
        Http::preventStrayRequests();
        Http::fake(['https://pypi.org/pypi/*/json' => Http::response(['info' => ['version' => '1.0.0']])]);

        $updates = app(CheckDependencyUpdates::class)->handle(app(ReadDependencies::class)->handle(Storage::path('')));

        $this->assertSame([500, 2, 2], [$updates['checked'], $updates['skipped'], $updates['incomplete']]);
        Http::assertSentCount(500);
    }

    public function test_gradle_sources_are_combined_and_security_scans_transitive_versions(): void
    {
        Storage::fake('local');
        Storage::put('gradle.lockfile', "org.example:main:1.0.0=runtimeClasspath\n");
        Storage::put('buildscript-gradle.lockfile', "org.example:build:2.0.0=classpath\n");
        Http::preventStrayRequests();
        Http::fake(['https://api.osv.dev/v1/querybatch' => Http::response(['results' => [(object) [], (object) []]])]);

        $snapshot = app(ReadDependencies::class)->handle(Storage::path(''));
        $security = app(CheckDependencySecurity::class)->handle($snapshot);

        $this->assertSame(['gradle.lockfile', 'buildscript-gradle.lockfile'], $snapshot['additional_ecosystems']['maven']['lockfiles']);
        $this->assertSame([2, 0, 0], [$security['checked'], $security['unavailable'], $security['incomplete']]);
        Http::assertSentCount(1);
    }

    public function test_python_lock_selection_is_ambiguous_and_does_not_use_stale_versions(): void
    {
        Storage::fake('local');
        Storage::put('pyproject.toml', "[project]\nname = \"app\"\ndependencies = [\"requests>=2\"]\n");
        Storage::put('uv.lock', "version = 1\n[[package]]\nname = \"requests\"\nversion = \"2.31.0\"\nsource = { registry = \"https://pypi.org/simple\" }\n");
        Storage::put('poetry.lock', "[[package]]\nname = \"requests\"\nversion = \"2.31.0\"\n[metadata]\nlock-version = \"2.1\"\n");
        Http::preventStrayRequests();
        $snapshot = app(ReadDependencies::class)->handle(Storage::path(''));

        $updates = app(CheckDependencyUpdates::class)->handle($snapshot);
        $security = app(CheckDependencySecurity::class)->handle($snapshot);

        $this->assertNull($snapshot['additional_ecosystems']['python']['lockfile']);
        $this->assertSame(1, $updates['skipped']);
        $this->assertSame(1, $security['skipped']);
        Http::assertNothingSent();
    }

    public function test_security_matches_python_names_and_pep440_fixed_versions(): void
    {
        Storage::fake('local');
        Storage::put('requirements.txt', "Example_Package==1!2.0rc1\n");
        Http::preventStrayRequests();
        Http::fake([
            'https://api.osv.dev/v1/querybatch' => Http::response(['results' => [['vulns' => [['id' => 'PYSEC-example']]]]]),
            'https://api.osv.dev/v1/vulns/PYSEC-example' => Http::response([
                'id' => 'PYSEC-example', 'modified' => '2026-10-02T00:00:00Z', 'summary' => 'Example advisory',
                'affected' => [['package' => ['name' => 'example.package', 'ecosystem' => 'PyPI'], 'ranges' => [['type' => 'ECOSYSTEM', 'events' => [['fixed' => '2.9'], ['fixed' => '1!2.0'], ['fixed' => '1!2.0.post1']]]]]],
            ]),
        ]);

        $security = app(CheckDependencySecurity::class)->handle(app(ReadDependencies::class)->handle(Storage::path('')));

        $this->assertSame(['1!2.0', '1!2.0.post1'], $security['packages'][0]['advisories'][0]['fixed_versions']);
        $this->assertSame(1, $security['checked']);
        Http::assertSentCount(2);
    }

    #[TestWith(['python', '1.0.dev1', '1.0a1', -1])]
    #[TestWith(['python', '1.0rc1', '1.0', -1])]
    #[TestWith(['python', '1.0', '1.0.post1', -1])]
    #[TestWith(['python', '1.0-1', '1.0.post1', 0])]
    #[TestWith(['python', '1!1.0', '9.9', 1])]
    #[TestWith(['python', '1.0+abc', '1.0+1', -1])]
    #[TestWith(['python', '1.0.0', '1.0', 0])]
    #[TestWith(['rust', '1.0.0-Beta', '1.0.0-alpha', -1])]
    #[TestWith(['npm', '1.0.0-rc.2', '1.0.0-rc.10', -1])]
    #[TestWith(['nuget', '1.0.0-BETA', '1.0.0-beta', 0])]
    #[TestWith(['dart', '1.0.0+build', '1.0.0', 0])]
    #[TestWith(['maven', '1.0alpha1', '1.0', -1])]
    public function test_compares_ecosystem_versions_correctly(string $ecosystem, string $first, string $second, int $comparison): void
    {
        $this->assertTrue(DependencyVersions::valid($ecosystem, $first));
        $this->assertTrue(DependencyVersions::valid($ecosystem, $second));

        $this->assertSame($comparison, DependencyVersions::compare($ecosystem, $first, $second) <=> 0);
    }

    #[TestWith(['1.0-1-2'])]
    #[TestWith(['1.0-1_2'])]
    #[TestWith(['99999999999999999999999!1.0'])]
    public function test_rejects_unsupported_python_versions(string $version): void
    {
        $this->assertFalse(DependencyVersions::valid('python', $version));
    }

    #[TestWith(['01.0.0'])]
    #[TestWith(['1.0.0.0'])]
    #[TestWith(['1.0.0-_invalid'])]
    #[TestWith(['1.0.0-01'])]
    #[TestWith(['1.0.0-..'])]
    #[TestWith(['1.0.0+bad..metadata'])]
    public function test_rejects_invalid_semantic_versions_in_new_ecosystems(string $version): void
    {
        foreach (['rust', 'go', 'dart'] as $ecosystem) {
            $this->assertFalse(DependencyVersions::valid($ecosystem, $version));
        }
    }

    public function test_new_sources_preserve_bounds_stale_data_and_fingerprint_selection(): void
    {
        Storage::fake('local');
        Storage::makeDirectory('project');
        Storage::put('project/requirements.txt', "requests==2.31.0\n");
        $reader = app(ReadDependencies::class);
        $snapshot = $reader->handle(Storage::path('project'));
        Storage::put('project/requirements.txt', str_repeat(' ', ReadDependencies::MANIFEST_LIMIT + 1));

        $stale = $reader->handle(Storage::path('project'), $snapshot);

        $this->assertSame('File too large', $stale['files']['requirements.txt']['state']);
        $this->assertSame($snapshot['files']['requirements.txt']['entries'], $stale['files']['requirements.txt']['entries']);
        $this->assertNotSame($snapshot['fingerprint'], $stale['fingerprint']);
        $changed = $snapshot;
        $changed['additional_ecosystems']['python']['lockfile'] = null;
        $this->assertNotSame($snapshot['fingerprint'], ReadDependencies::fingerprint($changed));
    }
}
