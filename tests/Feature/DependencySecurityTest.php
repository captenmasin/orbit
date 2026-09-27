<?php

namespace Tests\Feature;

use App\Actions\CheckDependencySecurity;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class DependencySecurityTest extends TestCase
{
    public function test_reports_transitive_vulnerabilities_and_each_distinct_locked_version(): void
    {
        $this->freezeTime();
        Http::preventStrayRequests();
        Http::fake([
            'https://api.osv.dev/v1/querybatch' => Http::response(['results' => [
                (object) [], ['vulns' => [['id' => 'GHSA-composer']]], (object) [],
                ['vulns' => [['id' => 'GHSA-npm']]], ['vulns' => [['id' => 'GHSA-npm']]],
            ]]),
            'https://api.osv.dev/v1/vulns/GHSA-composer' => Http::response([
                'id' => 'GHSA-composer', 'modified' => '2026-09-26T12:00:00Z', 'summary' => 'Unsafe deserialization',
                'database_specific' => ['severity' => 'HIGH'],
                'affected' => [['package' => ['name' => 'example/transitive', 'ecosystem' => 'Packagist'],
                    'ranges' => [['type' => 'ECOSYSTEM', 'events' => [['introduced' => '0'], ['fixed' => '1.0.2']]]]]],
            ]),
            'https://api.osv.dev/v1/vulns/GHSA-npm' => Http::response([
                'id' => 'GHSA-npm', 'modified' => '2026-09-26T12:00:00Z', 'summary' => 'Prototype pollution',
                'database_specific' => ['severity' => 'MODERATE'],
                'affected' => [
                    ['package' => ['name' => 'transitive', 'ecosystem' => 'npm'], 'ecosystem_specific' => ['severity' => 'CRITICAL'],
                        'ranges' => [['type' => 'SEMVER', 'events' => [['introduced' => '0'], ['fixed' => '3.0.0']]]]],
                    ['package' => ['name' => 'unrelated', 'ecosystem' => 'npm'],
                        'ranges' => [['type' => 'SEMVER', 'events' => [['fixed' => '9.0.0']]]]],
                ],
            ]),
        ]);
        $snapshot = $this->snapshot([
            ['name' => 'example/direct', 'version' => 'v1.0.0'], ['name' => 'example/transitive', 'version' => '1.0.0'],
        ], [
            ['name' => 'direct', 'version' => '1.0.0', 'location' => 'node_modules/direct'],
            ['name' => 'transitive', 'version' => '1.0.0', 'location' => 'node_modules/transitive'],
            ['name' => 'transitive', 'version' => '2.0.0', 'location' => 'node_modules/direct/node_modules/transitive'],
            ['name' => 'transitive', 'version' => '1.0.0', 'location' => 'node_modules/other/node_modules/transitive'],
        ]);

        $result = app(CheckDependencySecurity::class)->handle($snapshot);

        $this->assertSame(5, $result['checked']);
        $this->assertSame(0, $result['unavailable']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(now()->toIso8601String(), $result['checked_at']);
        $this->assertSame([
            ['name' => 'example/transitive', 'ecosystem' => 'composer', 'current' => '1.0.0', 'advisories' => [[
                'id' => 'GHSA-composer', 'title' => 'Unsafe deserialization', 'severity' => 'High',
                'url' => 'https://osv.dev/vulnerability/GHSA-composer', 'fixed_versions' => ['1.0.2'],
            ]]],
            ['name' => 'transitive', 'ecosystem' => 'npm', 'current' => '1.0.0', 'advisories' => [[
                'id' => 'GHSA-npm', 'title' => 'Prototype pollution', 'severity' => 'Critical',
                'url' => 'https://osv.dev/vulnerability/GHSA-npm', 'fixed_versions' => ['3.0.0'],
            ]]],
            ['name' => 'transitive', 'ecosystem' => 'npm', 'current' => '2.0.0', 'advisories' => [[
                'id' => 'GHSA-npm', 'title' => 'Prototype pollution', 'severity' => 'Critical',
                'url' => 'https://osv.dev/vulnerability/GHSA-npm', 'fixed_versions' => ['3.0.0'],
            ]]],
        ], $result['packages']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.osv.dev/v1/querybatch' && $request['queries'] === [
            ['package' => ['name' => 'example/direct', 'ecosystem' => 'Packagist'], 'version' => '1.0.0'],
            ['package' => ['name' => 'example/transitive', 'ecosystem' => 'Packagist'], 'version' => '1.0.0'],
            ['package' => ['name' => 'direct', 'ecosystem' => 'npm'], 'version' => '1.0.0'],
            ['package' => ['name' => 'transitive', 'ecosystem' => 'npm'], 'version' => '1.0.0'],
            ['package' => ['name' => 'transitive', 'ecosystem' => 'npm'], 'version' => '2.0.0'],
        ]);
        Http::assertSentCount(3);
    }

    public function test_clean_results_count_as_checked_without_advisory_requests(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.osv.dev/v1/querybatch' => Http::response(['results' => [(object) [], ['vulns' => []]]])]);

        $result = app(CheckDependencySecurity::class)->handle($this->snapshot([
            ['name' => 'example/one', 'version' => '1.0.0'], ['name' => 'example/two', 'version' => '1.0.0'],
        ]));

        $this->assertSame(2, $result['checked']);
        $this->assertSame(0, $result['unavailable']);
        $this->assertSame([], $result['packages']);
        Http::assertSentCount(1);
    }

    #[TestWith(['{"results":[]}', 200])]
    #[TestWith(['{"results":[[]]}', 200])]
    #[TestWith(['{"results":[{"vulns":null}]}', 200])]
    #[TestWith(['{"results":[{"error":"unavailable"}]}', 200])]
    #[TestWith(['{"results":[{"vulns":[{"id":"../invalid"}]}]}', 200])]
    #[TestWith(['not json', 200])]
    #[TestWith(['{"results":[{}]}', 503])]
    public function test_malformed_and_failed_queries_never_count_as_clean(string $payload, int $status): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.osv.dev/v1/querybatch' => Http::response($payload, $status)]);

        $result = app(CheckDependencySecurity::class)->handle($this->snapshot([['name' => 'example/package', 'version' => '1.0.0']]));

        $this->assertSame(0, $result['checked']);
        $this->assertSame(1, $result['unavailable']);
        $this->assertSame([], $result['packages']);
        Http::assertSentCount(1);
    }

    public function test_connection_failure_is_reported_as_unavailable(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.osv.dev/v1/querybatch' => Http::failedConnection()]);

        $result = app(CheckDependencySecurity::class)->handle($this->snapshot([['name' => 'example/package', 'version' => '1.0.0']]));

        $this->assertSame(0, $result['checked']);
        $this->assertSame(1, $result['unavailable']);
    }

    public function test_failed_advisory_details_preserve_the_known_vulnerability_with_unknown_severity(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.osv.dev/v1/querybatch' => Http::response(['results' => [['vulns' => [['id' => 'GHSA-known']]]]]),
            'https://api.osv.dev/v1/vulns/GHSA-known' => Http::failedConnection(),
        ]);

        $result = app(CheckDependencySecurity::class)->handle($this->snapshot([['name' => 'example/package', 'version' => '1.0.0']]));

        $this->assertSame(0, $result['checked']);
        $this->assertSame(1, $result['unavailable']);
        $this->assertSame([[
            'name' => 'example/package', 'ecosystem' => 'composer', 'current' => '1.0.0', 'advisories' => [[
                'id' => 'GHSA-known', 'title' => 'GHSA-known', 'severity' => 'Unknown',
                'url' => 'https://osv.dev/vulnerability/GHSA-known', 'fixed_versions' => [],
            ]],
        ]], $result['packages']);
        Http::assertSentCount(1);
    }

    #[TestWith([null, 1, 0])]
    #[TestWith(['more', 0, 1])]
    public function test_unknown_severity_is_kept_and_coverage_tracks_pagination(?string $pageToken, int $checked, int $unavailable): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.osv.dev/v1/querybatch' => Http::response(['results' => [['vulns' => [['id' => 'GHSA-known']], 'next_page_token' => $pageToken]]]),
            'https://api.osv.dev/v1/vulns/GHSA-known' => Http::response([
                'id' => 'GHSA-known', 'modified' => '2026-09-26T12:00:00Z',
                'severity' => [['type' => 'CVSS_V3', 'score' => 'CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:H']],
                'affected' => [['package' => ['name' => 'example/package', 'ecosystem' => 'Packagist']]],
            ]),
        ]);

        $result = app(CheckDependencySecurity::class)->handle($this->snapshot([['name' => 'example/package', 'version' => '1.0.0']]));

        $this->assertSame($checked, $result['checked']);
        $this->assertSame($unavailable, $result['unavailable']);
        $this->assertSame('Unknown', $result['packages'][0]['advisories'][0]['severity']);
        Http::assertSentCount(2);
    }

    #[TestWith(['{"id":"GHSA-other","modified":"2026-09-26","affected":[]}', 200])]
    #[TestWith(['{"id":"GHSA-known","modified":"2026-09-26","affected":[]}', 200])]
    #[TestWith(['{"id":"GHSA-known"}', 200])]
    #[TestWith(['not json', 200])]
    #[TestWith(['{}', 503])]
    public function test_malformed_advisory_details_preserve_findings_and_mark_the_package_unavailable(string $payload, int $status): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.osv.dev/v1/querybatch' => Http::response(['results' => [['vulns' => [['id' => 'GHSA-known']]]]]),
            'https://api.osv.dev/v1/vulns/GHSA-known' => Http::response($payload, $status),
        ]);

        $result = app(CheckDependencySecurity::class)->handle($this->snapshot([['name' => 'example/package', 'version' => '1.0.0']]));

        $this->assertSame(0, $result['checked']);
        $this->assertSame(1, $result['unavailable']);
        $this->assertSame('GHSA-known', $result['packages'][0]['advisories'][0]['id']);
        $this->assertSame('Unknown', $result['packages'][0]['advisories'][0]['severity']);
        Http::assertSentCount(2);
    }

    #[TestWith(['pnpm-lock.yaml'])]
    #[TestWith(['bun.lock'])]
    public function test_selected_npm_lock_source_is_checked_instead_of_a_competing_lockfile(string $lockfile): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.osv.dev/v1/querybatch' => Http::response(['results' => [(object) []]])]);
        $snapshot = $this->snapshot([], [['name' => 'old-npm', 'version' => '1.0.0']]);
        $snapshot['npm_lockfile'] = $lockfile;
        $snapshot['files'][$lockfile] = ['state' => 'Current', 'entries' => [['name' => 'transitive-package', 'version' => '2.0.0']]];

        $result = app(CheckDependencySecurity::class)->handle($snapshot);

        $this->assertSame(1, $result['checked']);
        $this->assertSame(0, $result['skipped']);
        Http::assertSent(fn (Request $request): bool => $request['queries'] === [['package' => ['name' => 'transitive-package', 'ecosystem' => 'npm'], 'version' => '2.0.0']]);
    }

    public function test_ambiguous_npm_sources_never_fall_back_to_the_npm_lockfile(): void
    {
        Http::preventStrayRequests();
        $snapshot = $this->snapshot([], [['name' => 'old-npm', 'version' => '1.0.0']]);
        $snapshot['npm_lockfile'] = null;

        $result = app(CheckDependencySecurity::class)->handle($snapshot);

        $this->assertSame(0, $result['checked']);
        $this->assertSame(1, $result['skipped']);
        Http::assertNothingSent();
    }

    public function test_unselected_bun_lock_is_incomplete_instead_of_an_absent_npm_ecosystem(): void
    {
        Http::preventStrayRequests();
        $snapshot = $this->snapshot();
        $snapshot['npm_lockfile'] = null;
        $snapshot['files']['bun.lock'] = ['state' => 'Current', 'entries' => [['name' => 'bun-package', 'version' => '1.0.0']]];

        $result = app(CheckDependencySecurity::class)->handle($snapshot);

        $this->assertSame(0, $result['checked']);
        $this->assertSame(1, $result['skipped']);
        Http::assertNothingSent();
    }

    public function test_a_composer_only_root_does_not_require_an_npm_lockfile(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.osv.dev/v1/querybatch' => Http::response(['results' => [(object) []]])]);
        $snapshot = $this->snapshot([['name' => 'example/package', 'version' => '1.0.0']]);
        $snapshot['npm_lockfile'] = null;

        $result = app(CheckDependencySecurity::class)->handle($snapshot);

        $this->assertSame(1, $result['checked']);
        $this->assertSame(0, $result['skipped']);
        Http::assertSentCount(1);
    }

    public function test_oversized_responses_are_unavailable(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.osv.dev/v1/querybatch' => Http::response(str_repeat(' ', 1048576).'{"results":[{}]}')]);

        $result = app(CheckDependencySecurity::class)->handle($this->snapshot([['name' => 'example/package', 'version' => '1.0.0']]));

        $this->assertSame(0, $result['checked']);
        $this->assertSame(1, $result['unavailable']);
        Http::assertSentCount(1);
    }

    public function test_missing_unsupported_and_unusable_lock_data_are_skipped_without_requests(): void
    {
        Http::preventStrayRequests();
        $snapshot = $this->snapshot();
        $snapshot['unsupported_lockfiles'] = ['pnpm-lock.yaml'];
        $snapshot['files']['composer.json'] = ['state' => 'Current', 'entries' => [['name' => 'example/missing-lock', 'required' => '^1.0']]];
        $snapshot['files']['package-lock.json'] = ['state' => 'Current', 'entries' => [
            ['name' => 'local', 'version' => '1.0.0', 'link' => '../local'],
            ['name' => 'invalid', 'version' => 'dev-main'],
        ]];

        $result = app(CheckDependencySecurity::class)->handle($snapshot);

        $this->assertSame(0, $result['checked']);
        $this->assertSame(4, $result['skipped']);
        $this->assertSame([], $result['packages']);
        Http::assertNothingSent();
    }

    public function test_package_limit_is_reported_as_incomplete(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.osv.dev/v1/querybatch' => Http::response(['results' => array_fill(0, 500, (object) [])])]);
        $entries = array_map(fn (int $index): array => ['name' => 'example/package-'.$index, 'version' => '1.0.0'], range(1, 501));

        $result = app(CheckDependencySecurity::class)->handle($this->snapshot($entries));

        $this->assertSame(500, $result['checked']);
        $this->assertSame(1, $result['skipped']);
        Http::assertSentCount(1);
    }

    public function test_advisory_limit_retains_unfetched_findings_and_marks_the_package_unavailable(): void
    {
        Http::preventStrayRequests();
        $vulnerabilities = array_map(fn (int $index): array => ['id' => 'GHSA-'.$index], range(1, 51));
        $fakes = ['https://api.osv.dev/v1/querybatch' => Http::response(['results' => [['vulns' => $vulnerabilities]]])];
        foreach (range(1, 50) as $index) {
            $fakes['https://api.osv.dev/v1/vulns/GHSA-'.$index] = Http::response([
                'id' => 'GHSA-'.$index, 'modified' => '2026-09-26T12:00:00Z',
                'affected' => [['package' => ['name' => 'example/package', 'ecosystem' => 'Packagist']]],
            ]);
        }
        Http::fake($fakes);

        $result = app(CheckDependencySecurity::class)->handle($this->snapshot([['name' => 'example/package', 'version' => '1.0.0']]));

        $this->assertSame(0, $result['checked']);
        $this->assertSame(1, $result['unavailable']);
        $this->assertCount(51, $result['packages'][0]['advisories']);
        $this->assertSame('GHSA-51', $result['packages'][0]['advisories'][50]['id']);
        $this->assertSame('Unknown', $result['packages'][0]['advisories'][50]['severity']);
        Http::assertSentCount(51);
    }

    /** @return array{version: int, files: array} */
    private function snapshot(array $composer = [], array $npm = []): array
    {
        return ['version' => 1, 'files' => [
            'composer.json' => ['state' => 'Missing file', 'entries' => []],
            'composer.lock' => ['state' => $composer ? 'Current' : 'Missing file', 'entries' => $composer],
            'package.json' => ['state' => 'Missing file', 'entries' => []],
            'package-lock.json' => ['state' => $npm ? 'Current' : 'Missing file', 'entries' => $npm],
        ]];
    }
}
