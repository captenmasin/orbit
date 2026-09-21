<?php

namespace Tests\Feature;

use App\Actions\ProtectCredential;
use App\Actions\ProviderHttp;
use App\Actions\QueueProviderRefresh;
use App\Jobs\RefreshProviderResource;
use App\Models\Project;
use App\Models\ProviderConnection;
use App\Models\ProviderSnapshot;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ProviderActivityTest extends TestCase
{
    use RefreshDatabase;

    private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private function repository(string $provider = 'github'): Repository
    {
        return Repository::factory()->for(ProviderConnection::factory()->state(['provider' => $provider]), 'providerConnection')->create([
            'provider_repository_id' => '42', 'provider_name' => 'team/repo', 'default_branch' => 'main',
        ])->fresh();
    }

    private function decrypt(): void
    {
        $this->mock(ProtectCredential::class, fn ($mock) => $mock->shouldReceive('decrypt')->andReturn('dummy-provider-token'));
    }

    private function refreshResource(Repository $repository, string $resource, bool $more = false): void
    {
        app(QueueProviderRefresh::class)->handle($repository, $resource, more: $more);
        $snapshot = $repository->providerSnapshots()->where('resource', $resource)->firstOrFail();
        $job = new RefreshProviderResource($snapshot->id, $snapshot->request_token, $repository->provider_revision, $repository->providerConnection->revision);
        app()->call([$job, 'handle']);
    }

    public static function providers(): array
    {
        return ['GitHub' => ['github'], 'GitLab' => ['gitlab']];
    }

    #[DataProvider('providers')]
    public function test_overview_uses_default_branch_committer_date_and_keeps_project_revision(string $provider): void
    {
        $repository = $this->repository($provider);
        $this->decrypt();
        Queue::fake([RefreshProviderResource::class]);
        Http::preventStrayRequests();
        $github = $provider === 'github';
        $base = $github ? 'https://api.github.com/repositories/42' : 'https://gitlab.com/api/v4/projects/42';
        Http::fake([
            $base => Http::response(['id' => 42, $github ? 'full_name' : 'path_with_namespace' => 'team/repo', 'default_branch' => 'release', $github ? 'html_url' : 'web_url' => 'https://'.($github ? 'github.com' : 'gitlab.com').'/team/repo']),
            $base.($github ? '/commits*' : '/repository/commits*') => Http::response([$github
                ? ['sha' => self::SHA, 'commit' => ['message' => 'Tip', 'committer' => ['date' => '2026-09-19T10:00:00+02:00'], 'author' => ['date' => '2020-01-01T00:00:00Z']], 'html_url' => 'https://github.com/team/repo/commit/'.self::SHA]
                : ['id' => self::SHA, 'title' => 'Tip', 'committed_date' => '2026-09-19T10:00:00+02:00', 'authored_date' => '2020-01-01T00:00:00Z', 'web_url' => 'https://gitlab.com/team/repo/-/commit/'.self::SHA]]),
        ]);

        $this->refreshResource($repository, 'overview');

        $this->assertSame('Current', $repository->providerSnapshots()->where('resource', 'overview')->first()->state);
        $this->assertSame('2026-09-19 08:00:00', $repository->fresh()->remote_commit_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, $repository->project->fresh()->revision);
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('projects.data.0.last_commit_at', '2026-09-19T08:00:00.000000Z'));
        Http::assertSent(fn ($request) => ($request[$github ? 'sha' : 'ref_name'] ?? null) === 'release');
        Queue::assertPushed(RefreshProviderResource::class, $github ? 5 : 4);
    }

    public function test_issue_pages_filter_pull_requests_deduplicate_and_preserve_data_on_failure(): void
    {
        $repository = $this->repository();
        $this->decrypt();
        Queue::fake([RefreshProviderResource::class]);
        Http::preventStrayRequests();
        $issue = ['id' => 1, 'number' => 7, 'title' => '<script>escaped by Vue</script>', 'state' => 'open', 'updated_at' => '2026-09-19T00:00:00Z', 'html_url' => 'https://github.com/team/repo/issues/7'];
        Http::fake(['https://api.github.com/repositories/42/issues*' => Http::sequence()
            ->push([$issue, ['pull_request' => []]], 200, ['Link' => '<https://api.github.com/repositories/42/issues?page=2>; rel="next"'])
            ->push([$issue, [...$issue, 'id' => 2, 'number' => 8]], 200)
            ->push(['message' => 'dummy-provider-token'], 503)]);

        $this->refreshResource($repository, 'issues');
        $this->assertSame(2, $repository->providerSnapshots()->sole()->next_page);
        $this->refreshResource($repository, 'issues', more: true);
        $this->assertCount(2, $repository->providerSnapshots()->sole()->payload['items']);
        $this->refreshResource($repository, 'issues');

        $snapshot = $repository->providerSnapshots()->sole();
        $this->assertSame('Stale', $snapshot->state);
        $this->assertCount(2, $snapshot->payload['items']);
        $this->assertStringNotContainsString('dummy-provider-token', $snapshot->toJson());
        Http::assertSentCount(3);
        Queue::assertPushed(RefreshProviderResource::class, 3);
    }

    public function test_rate_limits_and_revoked_tokens_stop_further_requests(): void
    {
        $this->travelTo(now()->startOfSecond());
        $repository = $this->repository();
        $this->decrypt();
        Queue::fake([RefreshProviderResource::class]);
        Http::preventStrayRequests();
        Http::fake(['https://api.github.com/repositories/42/issues*' => Http::sequence()->push([], 429, ['Retry-After' => '120'])->push([], 401)]);

        $this->refreshResource($repository, 'issues');
        $this->assertSame('Rate limited', $repository->providerConnection->fresh()->state);
        $this->assertFalse(app(QueueProviderRefresh::class)->handle($repository, 'issues'));
        $this->travel(121)->seconds();
        $this->refreshResource($repository, 'issues');
        $this->assertSame('Token required', $repository->providerConnection->fresh()->state);
        $this->assertFalse(app(QueueProviderRefresh::class)->handle($repository, 'issues'));
        Http::assertSentCount(2);
        Queue::assertPushed(RefreshProviderResource::class, 2);
    }

    public function test_old_jobs_cannot_publish_after_disconnect_or_replace(): void
    {
        $repository = $this->repository();
        $this->decrypt();
        Queue::fake([RefreshProviderResource::class]);
        Http::preventStrayRequests();
        Http::fake(['https://api.github.com/repositories/42/issues*' => function () use ($repository) {
            $repository->disconnectProvider();
            $repository->save();

            return Http::response([]);
        }]);

        $this->refreshResource($repository, 'issues');

        $this->assertDatabaseCount('provider_snapshots', 0);
        $this->assertNull($repository->fresh()->provider_connection_id);
        Http::assertSentCount(1);
        Queue::assertPushed(RefreshProviderResource::class);
    }

    public static function unsafeResponses(): array
    {
        return ['redirect' => [302, ['Location' => 'https://evil.test/steal'], []], 'page origin' => [200, ['Link' => '<https://evil.test/items?page=2>; rel="next"'], []], 'invalid json shape' => [200, [], 'not-json'], 'oversized' => [200, [], str_repeat('a', 2097153)]];
    }

    #[DataProvider('unsafeResponses')]
    public function test_unsafe_responses_are_rejected_without_forwarding_credentials(int $status, array $headers, array|string $body): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.github.com/user' => Http::response($body, $status, $headers)]);
        try {
            app(ProviderHttp::class)->get('github', '/user', 'dummy-token');
            $this->fail('Unsafe response accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('dummy-token', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_association_is_project_scoped_and_remote_edits_invalidate_snapshots(): void
    {
        $repository = $this->repository();
        ProviderSnapshot::factory()->for($repository)->create();
        $other = Project::factory()->create();
        $this->postJson('/projects/'.$other->id.'/repositories/'.$repository->id.'/connection', ['revision' => 1])->assertNotFound();
        $this->postJson('/projects/'.$repository->project_id.'/repositories/'.$repository->id.'/connection', ['revision' => 2])->assertConflict();

        $repository->update(['remote_url' => 'https://github.com/team/changed']);

        $this->assertNull($repository->fresh()->provider_connection_id);
        $this->assertDatabaseCount('provider_snapshots', 0);
    }

    #[DataProvider('providers')]
    public function test_ci_is_bound_to_the_displayed_commit_and_requests_preserve_provider_ids(string $provider): void
    {
        $repository = $this->repository($provider);
        $this->decrypt();
        Queue::fake([RefreshProviderResource::class]);
        Http::preventStrayRequests();
        ProviderSnapshot::factory()->for($repository)->create(['resource' => 'overview', 'state' => 'Current', 'payload' => ['commit' => ['sha' => self::SHA]]]);
        $github = $provider === 'github';
        $base = $github ? 'https://api.github.com/repositories/42' : 'https://gitlab.com/api/v4/projects/42';
        $row = $github ? ['id' => 1, 'head_sha' => self::SHA, 'head_branch' => 'main', 'name' => 'Build', 'status' => 'completed', 'conclusion' => 'success', 'html_url' => 'https://github.com/team/repo/actions/runs/1']
            : ['id' => 1, 'sha' => self::SHA, 'ref' => 'main', 'status' => 'success', 'web_url' => 'https://gitlab.com/team/repo/-/pipelines/1'];
        $other = [...$row, 'id' => 2, $github ? 'head_sha' : 'sha' => str_repeat('b', 40)];
        Http::fake([
            $base.($github ? '/actions/runs*' : '/pipelines*') => Http::response($github ? ['workflow_runs' => [$row, $other, [...$row, 'id' => 3, 'head_branch' => 'other']]] : [$row, $other, [...$row, 'id' => 3, 'ref' => 'other']]),
            $base.($github ? '/pulls*' : '/merge_requests*') => Http::response([['id' => 123, $github ? 'number' : 'iid' => 4, 'title' => 'Change', 'state' => $github ? 'open' : 'opened', 'updated_at' => '2026-09-19T00:00:00Z', $github ? 'html_url' : 'web_url' => 'https://'.($github ? 'github.com' : 'gitlab.com').'/team/repo/requests/4']]),
        ]);

        $this->refreshResource($repository, 'checks');
        $this->refreshResource($repository, 'requests');

        $snapshot = $repository->providerSnapshots()->where('resource', 'checks')->first();
        $this->assertSame('Current', $snapshot->state);
        $this->assertSame(self::SHA, $snapshot->payload['sha']);
        $this->assertCount(1, $snapshot->payload['items']);
        $this->assertSame('success', $snapshot->payload['items'][0]['state']);
        Http::assertSent(fn ($request) => ($request[$github ? 'head_sha' : 'sha'] ?? null) === self::SHA && ($request[$github ? 'branch' : 'ref'] ?? null) === 'main');
        $this->assertSame('4', $repository->providerSnapshots()->where('resource', 'requests')->first()->payload['items'][0]['number']);
        Http::assertSentCount(2);
        Queue::assertPushed(RefreshProviderResource::class, 2);
    }

    public function test_github_actions_pages_keep_run_states_and_survive_permission_failure(): void
    {
        $repository = $this->repository();
        $this->decrypt();
        Queue::fake([RefreshProviderResource::class]);
        Http::preventStrayRequests();
        ProviderSnapshot::factory()->for($repository)->create(['resource' => 'overview', 'payload' => ['commit' => ['sha' => self::SHA]]]);
        $snapshot = ProviderSnapshot::factory()->for($repository)->create(['resource' => 'checks', 'state' => 'Current', 'etag' => '"old-checks"', 'payload' => ['sha' => self::SHA, 'items' => []]]);
        $run = ['id' => 10, 'head_sha' => self::SHA, 'head_branch' => 'main', 'name' => 'Build', 'status' => 'in_progress', 'conclusion' => null, 'html_url' => 'https://github.com/team/repo/actions/runs/10'];
        Http::fake(['https://api.github.com/repositories/42/actions/runs*' => Http::sequence()
            ->push(['workflow_runs' => [$run]], 200, ['ETag' => '"actions"', 'Link' => '<https://api.github.com/repositories/42/actions/runs?page=2>; rel="next"'])
            ->push(['workflow_runs' => [$run, [...$run, 'id' => 11, 'status' => 'completed', 'conclusion' => 'failure']]])
            ->push([], 403)]);

        $this->refreshResource($repository, 'checks');
        $this->assertSame(2, $snapshot->fresh()->next_page);
        $this->assertSame('actions', $snapshot->fresh()->payload['source']);
        Http::assertSent(fn ($request) => ! $request->hasHeader('If-None-Match'));
        $this->refreshResource($repository, 'checks', more: true);
        $this->assertSame(['in_progress', 'failure'], array_column($snapshot->fresh()->payload['items'], 'state'));
        $payload = $snapshot->fresh()->payload;
        $this->refreshResource($repository, 'checks');

        $this->assertSame('Access unavailable', $snapshot->fresh()->state);
        $this->assertSame('GitHub Actions requires Actions: read permission for this repository.', $snapshot->fresh()->error);
        $this->assertSame($payload, $snapshot->fresh()->payload);
        $this->assertSame('Current', $repository->providerConnection->fresh()->state);
        Http::assertSent(fn ($request) => $request['page'] === 2 && $request['head_sha'] === self::SHA);
        Http::assertSentCount(3);
        Queue::assertPushed(RefreshProviderResource::class, 3);
    }

    public function test_conditional_response_retains_content_and_data_time_but_advances_check_time(): void
    {
        $this->travelTo(now()->startOfSecond());
        $repository = $this->repository();
        $this->decrypt();
        Queue::fake([RefreshProviderResource::class]);
        Http::preventStrayRequests();
        $snapshot = ProviderSnapshot::factory()->for($repository)->create(['payload' => ['items' => []], 'etag' => '"abc"', 'state' => 'Current', 'succeeded_at' => now()->subDay(), 'checked_at' => now()->subDay()]);
        $originalTime = $snapshot->succeeded_at;
        Http::fake(['https://api.github.com/repositories/42/issues*' => Http::response('', 304)]);

        $this->refreshResource($repository, 'issues');

        $this->assertTrue($snapshot->fresh()->succeeded_at->equalTo($originalTime));
        $this->assertTrue($snapshot->fresh()->checked_at->equalTo(now()));
        $this->assertSame(['items' => []], $snapshot->fresh()->payload);
        Http::assertSent(fn ($request) => $request->hasHeader('If-None-Match', '"abc"'));
        Queue::assertPushed(RefreshProviderResource::class);
    }

    public function test_permission_failure_is_isolated_and_automatic_refresh_does_not_retry_it(): void
    {
        $repository = $this->repository();
        $this->decrypt();
        Queue::fake([RefreshProviderResource::class]);
        Http::preventStrayRequests();
        Http::fake(['https://api.github.com/repositories/42/issues*' => Http::response([], 403), 'https://api.github.com/repositories/42/pulls*' => Http::response([])]);

        $this->refreshResource($repository, 'issues');
        $this->refreshResource($repository, 'requests');
        $this->travel(6)->minutes();

        $this->assertSame('Access unavailable', $repository->providerSnapshots()->where('resource', 'issues')->first()->state);
        $this->assertSame('Current', $repository->providerSnapshots()->where('resource', 'requests')->first()->state);
        $this->assertFalse(app(QueueProviderRefresh::class)->handle($repository, 'issues', onlyStale: true));
        Http::assertSentCount(2);
        Queue::assertPushed(RefreshProviderResource::class, 2);
    }

    public function test_association_verifies_name_before_queueing_and_job_payload_has_no_credentials(): void
    {
        $repository = Repository::factory()->create()->fresh();
        $connection = ProviderConnection::factory()->create()->fresh();
        $this->decrypt();
        Queue::fake([RefreshProviderResource::class]);
        Http::preventStrayRequests();
        Http::fake(['https://api.github.com/repos/team/repo' => Http::response(['id' => 42, 'full_name' => 'team/repo', 'default_branch' => 'main', 'html_url' => 'https://github.com/team/repo'])]);

        $this->postJson('/projects/'.$repository->project_id.'/repositories/'.$repository->id.'/connection', ['revision' => 1, 'connection_id' => $connection->id, 'name' => 'team/repo'])->assertOk();

        $this->assertSame('42', $repository->fresh()->provider_repository_id);
        $this->assertSame(1, $repository->project->fresh()->revision);
        Queue::assertPushed(RefreshProviderResource::class, fn ($job) => ! str_contains(serialize($job), 'dummy-provider-token') && ! str_contains(serialize($job), 'fixture-ciphertext'));
        Http::assertSentCount(1);
    }
}
