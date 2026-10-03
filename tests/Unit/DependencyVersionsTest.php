<?php

namespace Tests\Unit;

use App\DependencyVersions;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DependencyVersionsTest extends TestCase
{
    #[TestWith(['ruby', '1.0', '1.0.0', 0])]
    #[TestWith(['ruby', '1.0.foo', '1.0.bar', 1])]
    #[TestWith(['ruby', '1.0.alpha', '1.0.a', 1])]
    #[TestWith(['ruby', '1.0.0.a', '1.0.a', 0])]
    #[TestWith(['ruby', '1.0.a.0', '1.0.a', 0])]
    #[TestWith(['ruby', '1.0.a10', '1.0.a9', 1])]
    #[TestWith(['ruby', '1.0', '1.0.z', 1])]
    #[TestWith(['ruby', '1.0-rc1', '1.0.pre.rc1', 0])]
    #[TestWith(['ruby', '1.0.Z', '1.0.a', -1])]
    #[TestWith(['maven', '1.0', '1.0.0', 0])]
    #[TestWith(['maven', '1.0.Final', '1.0-rc1', 1])]
    #[TestWith(['maven', '1.0.GA', '1.0.0.Final', 0])]
    #[TestWith(['maven', '1.0-release', '1.0', 0])]
    #[TestWith(['maven', '1.0-alpha1', '1.0-a1', 0])]
    #[TestWith(['maven', '1.0alpha1', '1.0-beta1', -1])]
    #[TestWith(['maven', '1.0-beta1', '1.0-milestone1', -1])]
    #[TestWith(['maven', '1.0-m1', '1.0-rc1', -1])]
    #[TestWith(['maven', '1.0-CR1', '1.0-rc1', 0])]
    #[TestWith(['maven', '1.0-RC2', '1.0-RC10', -1])]
    #[TestWith(['maven', '1.0-RC1', '1.0-SNAPSHOT', -1])]
    #[TestWith(['maven', '1.0-SNAPSHOT', '1.0', -1])]
    #[TestWith(['maven', '1.0', '1.0-sp1', -1])]
    #[TestWith(['maven', '1.0-sp1', '1.0-jre', -1])]
    #[TestWith(['maven', '1.0-jre', '1.0-JRE', 0])]
    #[TestWith(['maven', '1.0-foo', '1.0-bar', 1])]
    #[TestWith(['maven', '1.0-rc3', '1.0.1', -1])]
    public function test_ecosystem_versions_follow_release_and_qualifier_ordering(string $ecosystem, string $first, string $second, int $expected): void
    {
        $this->assertTrue(DependencyVersions::comparable($ecosystem, $first));
        $this->assertTrue(DependencyVersions::comparable($ecosystem, $second));
        $this->assertSame($expected, DependencyVersions::compare($ecosystem, $first, $second) <=> 0);
        $this->assertSame(-$expected, DependencyVersions::compare($ecosystem, $second, $first) <=> 0);
    }

    #[TestWith(['1.0.Final', true])]
    #[TestWith(['1.0-GA', true])]
    #[TestWith(['1.0-sp1', true])]
    #[TestWith(['33.4.8-jre', true])]
    #[TestWith(['1.0-alpha1', false])]
    #[TestWith(['1.0-RC1', false])]
    #[TestWith(['1.0-SNAPSHOT', false])]
    #[TestWith(['1.0-alpha.1', false])]
    #[TestWith(['1.0.0.Final-redhat-00001', false])]
    public function test_maven_latest_versions_exclude_prereleases_and_unsupported_nested_qualifiers(string $version, bool $stable): void
    {
        $this->assertTrue(DependencyVersions::valid('maven', $version));
        $this->assertSame($stable, DependencyVersions::valid('maven', $version, stable: true));
    }

    #[TestWith(['1.0-alpha.1'])]
    #[TestWith(['1.0.0.Final-redhat-00001'])]
    public function test_nested_maven_qualifiers_can_be_scanned_exactly_but_cannot_be_compared(string $version): void
    {
        $this->assertTrue(DependencyVersions::valid('maven', $version));
        $this->assertFalse(DependencyVersions::comparable('maven', $version));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported version comparison');

        DependencyVersions::compare('maven', $version, '2.0');
    }

    #[TestWith(['1.0.0', true, true])]
    #[TestWith(['1.0.foo', true, false])]
    #[TestWith(['1.0-rc1', true, false])]
    #[TestWith(['1.0+metadata', false, false])]
    #[TestWith(['1.0_bad', false, false])]
    public function test_ruby_versions_reject_non_gem_syntax_and_keep_prereleases_out_of_latest_selection(string $version, bool $valid, bool $stable): void
    {
        $this->assertSame($valid, DependencyVersions::valid('ruby', $version));
        $this->assertSame($stable, DependencyVersions::valid('ruby', $version, stable: true));
    }
}
