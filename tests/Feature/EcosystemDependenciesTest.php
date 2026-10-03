<?php

namespace Tests\Feature;

use App\Actions\ReadDependencies;
use App\Actions\ReadEcosystemDependencies;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class EcosystemDependenciesTest extends TestCase
{
    public function test_python_pins_ranges_markers_urls_hashes_and_includes_keep_their_meaning(): void
    {
        $contents = <<<'TXT'
# Generated requirements
Django==5.1.2
Requests[security] == 2.32.3 ; python_version >= "3.10"
Py_YAML>=6,<7
flask
numpy==2.*
package @ git+https://github.com/example/package.git#main
-r ../private.txt
-e ./local
click==8.1.7 \
    --hash=sha256:abcdef
TXT;

        $entries = app(ReadEcosystemDependencies::class)->handle('requirements.txt', $contents)['entries'];

        $this->assertSame(['django', 'requests', 'py-yaml', 'flask', 'numpy', 'package', '-r ../private.txt', '-e ./local', 'click'], array_column($entries, 'name'));
        $this->assertSame(['5.1.2', '2.32.3', null, null, null, null, null, null, '8.1.7'], array_column($entries, 'version'));
        $this->assertSame('Requests[security] == 2.32.3 ; python_version >= "3.10"', $entries[1]['required']);
        $this->assertSame('git+https://github.com/example/package.git#main', $entries[5]['link']);
        $this->assertSame('-r ../private.txt', $entries[6]['link']);
    }

    public function test_custom_python_sources_never_scan_pins_as_pypi_packages(): void
    {
        $contents = "--extra-index-url https://private.example/simple\nrequests==2.32.3\n";

        $entries = app(ReadEcosystemDependencies::class)->handle('requirements.txt', $contents)['entries'];

        $this->assertNull($entries[0]['version']);
        $this->assertSame('--extra-index-url https://private.example/simple', $entries[0]['link']);
    }

    public function test_go_versions_indirect_dependencies_runtime_and_replacements_are_preserved(): void
    {
        $contents = <<<'MOD'
module example.com/app

go 1.24.0
toolchain go1.24.2
require (
    golang.org/x/net v0.33.0
    example.com/local v1.0.0
    example.com/forked v2.0.0+incompatible // indirect
    example.com/excluded v1.0.0
)
replace (
    example.com/local => ../local
    example.com/forked v2.0.0+incompatible => example.com/fork v2.1.0+incompatible
)
exclude example.com/excluded v1.0.0
MOD;

        $source = app(ReadEcosystemDependencies::class)->handle('go.mod', $contents);

        $this->assertSame(['go' => '1.24.0', 'toolchain' => 'go1.24.2'], $source['requirements']);
        $this->assertSame(['v0.33.0', null, null, null], array_column($source['entries'], 'version'));
        $this->assertSame('Transitive', $source['entries'][2]['identity']);
        $this->assertSame('../local', $source['entries'][1]['link']);
        $this->assertSame('example.com/fork v2.1.0+incompatible', $source['entries'][2]['link']);
        $this->assertSame('Excluded version', $source['entries'][3]['link']);
    }

    public function test_ruby_locks_keep_platforms_direct_identity_and_non_registry_sources(): void
    {
        $contents = <<<'LOCK'
GIT
  remote: https://github.com/example/custom.git
  revision: abcdef
  specs:
    custom (1.0.0)

PATH
  remote: ../local
  specs:
    local (2.0.0)

GEM
  remote: https://rubygems.org/
  specs:
    nokogiri (1.16.8-x86_64-linux)
      racc (~> 1.4)
    racc (1.8.1)

PLATFORMS
  x86_64-linux

DEPENDENCIES
  custom!
  local!
  nokogiri (~> 1.16)

RUBY VERSION
   ruby 3.3.0p0

BUNDLED WITH
   2.5.23
LOCK;

        $source = app(ReadEcosystemDependencies::class)->handle('Gemfile.lock', $contents);

        $this->assertSame([null, null, '1.16.8', '1.8.1'], array_column($source['entries'], 'version'));
        $this->assertSame(['Direct', 'Direct', 'Direct', 'Transitive'], array_column($source['entries'], 'identity'));
        $this->assertSame('x86_64-linux', $source['entries'][2]['location']);
        $this->assertSame('https://github.com/example/custom.git', $source['entries'][0]['link']);
        $this->assertSame(['ruby' => 'ruby 3.3.0p0'], $source['requirements']);
    }

    public function test_nuget_preserves_framework_specific_versions_and_project_references(): void
    {
        $contents = json_encode(['version' => 2, 'dependencies' => [
            'net8.0' => [
                'Newtonsoft.Json' => ['type' => 'Direct', 'requested' => '[13.0.1, )', 'resolved' => '13.0.3'],
                'System.Text.Json' => ['type' => 'Transitive', 'resolved' => '8.0.5'],
                'SharedProject' => ['type' => 'Project'],
            ],
            'net9.0' => ['System.Text.Json' => ['type' => 'Transitive', 'resolved' => '9.0.1']],
        ]]);

        $source = app(ReadEcosystemDependencies::class)->handle('packages.lock.json', $contents);

        $this->assertSame(2, $source['lockfile_version']);
        $this->assertSame(['13.0.3', '8.0.5', null, '9.0.1'], array_column($source['entries'], 'version'));
        $this->assertSame(['net8.0', 'net8.0', 'net8.0', 'net9.0'], array_column($source['entries'], 'location'));
        $this->assertSame('[13.0.1, )', $source['entries'][0]['required']);
        $this->assertSame('Project reference', $source['entries'][2]['link']);
    }

    public function test_dart_only_resolves_hosted_packages_from_the_public_registry(): void
    {
        $contents = <<<'YAML'
packages:
  http:
    dependency: "direct main"
    description:
      name: http
      url: "https://pub.dev"
    source: hosted
    version: "1.2.2"
  test:
    dependency: "direct dev"
    description: "https://pub.dartlang.org"
    source: hosted
    version: "1.25.8"
  custom:
    dependency: transitive
    description:
      name: custom
      url: "https://private.example"
    source: hosted
    version: "1.0.0"
  local:
    dependency: "direct main"
    description:
      path: ../local
      relative: true
    source: path
    version: "1.0.0"
sdks:
  dart: ">=3.4.0 <4.0.0"
YAML;

        $source = app(ReadEcosystemDependencies::class)->handle('pubspec.lock', $contents);

        $this->assertSame(['1.2.2', '1.25.8', null, null], array_column($source['entries'], 'version'));
        $this->assertSame(['Production', 'Development', 'Unknown', 'Production'], array_column($source['entries'], 'scope'));
        $this->assertSame('https://private.example', $source['entries'][2]['link']);
        $this->assertSame('../local', $source['entries'][3]['link']);
        $this->assertSame(['dart' => '>=3.4.0 <4.0.0'], $source['requirements']);
    }

    public function test_maven_resolves_only_local_properties_and_keeps_unknown_parents_ranges_and_profiles_visible(): void
    {
        $contents = <<<'XML'
<project xmlns="http://maven.apache.org/POM/4.0.0">
  <modelVersion>4.0.0</modelVersion>
  <parent><groupId>org.example</groupId><artifactId>parent</artifactId><version>2.0.0</version></parent>
  <artifactId>app</artifactId>
  <properties><log.version>2.24.1</log.version><nested.version>${log.version}</nested.version></properties>
  <dependencyManagement><dependencies><dependency><groupId>org.example</groupId><artifactId>managed</artifactId><version>${nested.version}</version></dependency></dependencies></dependencyManagement>
  <dependencies>
    <dependency><groupId>org.apache.logging.log4j</groupId><artifactId>log4j-core</artifactId><version>${nested.version}</version></dependency>
    <dependency><groupId>org.example</groupId><artifactId>managed</artifactId><scope>test</scope></dependency>
    <dependency><groupId>org.example</groupId><artifactId>external</artifactId><version>${parent.external.version}</version></dependency>
    <dependency><groupId>org.example</groupId><artifactId>range</artifactId><version>[1.0,2.0)</version><optional>true</optional></dependency>
  </dependencies>
  <profiles><profile><id>optional</id><dependencies><dependency><groupId>org.example</groupId><artifactId>profile</artifactId><version>1.0</version></dependency></dependencies></profile></profiles>
</project>
XML;

        $source = app(ReadEcosystemDependencies::class)->handle('pom.xml', $contents);

        $this->assertSame(['2.24.1', '2.24.1', null, null, null, null], array_column($source['entries'], 'version'));
        $this->assertSame('${parent.external.version}', $source['entries'][2]['required']);
        $this->assertSame('Development', $source['entries'][1]['scope']);
        $this->assertSame('Optional', $source['entries'][3]['scope']);
        $this->assertSame('Profile activation unknown', $source['entries'][4]['link']);
        $this->assertSame('Parent POM', $source['entries'][5]['link']);
    }

    public function test_maven_private_repositories_and_imported_boms_are_visible_without_public_registry_guesses(): void
    {
        $contents = <<<'XML'
<project>
  <repositories><repository><url>https://private.example/maven</url></repository></repositories>
  <dependencyManagement><dependencies>
    <dependency><groupId>org.example</groupId><artifactId>bom</artifactId><version>2.0.0</version><type>pom</type><scope>import</scope></dependency>
  </dependencies></dependencyManagement>
  <dependencies>
    <dependency><groupId>org.example</groupId><artifactId>private</artifactId><version>1.0.0</version></dependency>
    <dependency><groupId>org.example</groupId><artifactId>managed</artifactId></dependency>
  </dependencies>
</project>
XML;

        $source = app(ReadEcosystemDependencies::class)->handle('pom.xml', $contents);

        $this->assertSame(['org.example:private', 'org.example:managed', 'org.example:bom'], array_column($source['entries'], 'name'));
        $this->assertSame([null, null, null], array_column($source['entries'], 'version'));
        $this->assertSame('https://private.example/maven', $source['entries'][0]['link']);
        $this->assertSame('Imported BOM', $source['entries'][2]['link']);
        $this->assertSame('2.0.0', $source['entries'][2]['required']);
    }

    #[TestWith(['gradle.lockfile', 'org.example:runtime:1.2.3=runtimeClasspath,testRuntimeClasspath', 'Unknown', 'org.example:runtime', '1.2.3'])]
    #[TestWith(['gradle.lockfile', 'org.example:test:2.0.0=testCompileClasspath,testRuntimeClasspath', 'Development', 'org.example:test', '2.0.0'])]
    #[TestWith(['buildscript-gradle.lockfile', 'org.example:plugin:3.0.0=classpath', 'Development', 'org.example:plugin', '3.0.0'])]
    #[TestWith(['gradle/dependency-locks/testCompileClasspath.lockfile', 'org.example:legacy:4.0.0', 'Development', 'org.example:legacy', '4.0.0'])]
    public function test_gradle_modern_and_legacy_locks_preserve_coordinates_configurations_and_scope(string $file, string $line, string $scope, string $name, string $version): void
    {
        $source = app(ReadEcosystemDependencies::class)->handle($file, "# Generated lock\n".$line."\nempty=annotationProcessor\n");

        $this->assertCount(1, $source['entries']);
        $this->assertSame($scope, $source['entries'][0]['scope']);
        $this->assertSame('Lock', $source['entries'][0]['identity']);
        $this->assertSame($name, $source['entries'][0]['name']);
        $this->assertSame($version, $source['entries'][0]['version']);
    }

    #[TestWith(['go.mod', "module example.com/app\nrequire (\nexample.com/a v1.0.0\n", 'Malformed file'])]
    #[TestWith(['Gemfile.lock', 'not a lockfile', 'Malformed file'])]
    #[TestWith(['Gemfile.lock', "GEM\n  remote: https://rubygems.org/\n  specs:\n    package broken\n", 'Malformed file'])]
    #[TestWith(['packages.lock.json', '{"version":3,"dependencies":{}}', 'Unsupported lockfile version'])]
    #[TestWith(['packages.lock.json', '{"version":1,"dependencies":[]}', 'Malformed file'])]
    #[TestWith(['packages.lock.json', '{"version":1,"dependencies":{"net8.0":{"a":{"type":"Direct","resolved":1}}}}', 'Malformed file'])]
    #[TestWith(['pubspec.lock', "packages: &alias {}\nother: *alias\n", 'Malformed file'])]
    #[TestWith(['pubspec.lock', "packages: [broken\n", 'Malformed file'])]
    #[TestWith(['pubspec.lock', "packages: {}\nsdks: broken\n", 'Malformed file'])]
    #[TestWith(['pom.xml', '<!DOCTYPE project [<!ENTITY secret SYSTEM "file:///etc/passwd">]><project><dependencies>&secret;</dependencies></project>', 'Malformed file'])]
    #[TestWith(['pom.xml', '<project><dependencies>', 'Malformed file'])]
    #[TestWith(['gradle.lockfile', 'invalid lock entry', 'Malformed file'])]
    public function test_invalid_and_unsupported_manifests_fail_instead_of_returning_guessed_packages(string $file, string $contents, string $message): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        app(ReadEcosystemDependencies::class)->handle($file, $contents);
    }

    public function test_additional_formats_enforce_the_existing_package_entry_limit(): void
    {
        $contents = str_repeat("package==1.0.0\n", ReadDependencies::ENTRY_LIMIT + 1);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Too many entries');

        app(ReadEcosystemDependencies::class)->handle('requirements.txt', $contents);
    }
}
