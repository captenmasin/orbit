<?php

namespace Tests\Feature;

use App\Actions\ProtectCredential;
use App\Models\ProviderConnection;
use App\Models\ProviderSnapshot;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Native\Desktop\Facades\System;
use RuntimeException;
use Tests\TestCase;

class ProviderCredentialTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_storage_refuses_browser_plaintext_and_sanitizes_bridge_failures(): void
    {
        foreach ([false, true] as $native) {
            config(['nativephp-internal.running' => $native]);
            if ($native) {
                System::shouldReceive('canEncrypt')->once()->andThrow(new RuntimeException('SECRET-BRIDGE-TOKEN'));
            }
            try {
                app(ProtectCredential::class)->encrypt('SECRET-BRIDGE-TOKEN');
                $this->fail('Encryption should fail closed.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Native credential storage is unavailable. Open the desktop app and try again.', $exception->getMessage());
                $this->assertNull($exception->getPrevious());
            }
        }
    }

    public function test_null_or_plaintext_encryption_results_are_rejected(): void
    {
        config(['nativephp-internal.running' => true]);
        System::shouldReceive('canEncrypt')->times(3)->andReturn(true);
        System::shouldReceive('encrypt')->with('dummy-token')->times(3)->andReturn(null, 'dummy-token', '');
        foreach ([1, 2, 3] as $attempt) {
            try {
                app(ProtectCredential::class)->encrypt('dummy-token');
                $this->fail('Expected failure.');
            } catch (RuntimeException $exception) {
                $this->assertStringNotContainsString('dummy-token', $exception->getMessage());
            }
        }
    }

    public function test_connection_saves_only_ciphertext_and_never_flashes_or_renders_tokens(): void
    {
        config(['nativephp-internal.running' => true]);
        System::shouldReceive('canEncrypt')->twice()->andReturn(true);
        System::shouldReceive('encrypt')->once()->with('DISTINCT-DUMMY-TOKEN')->andReturn('native-ciphertext');
        System::shouldReceive('decrypt')->once()->with('native-ciphertext')->andReturn('DISTINCT-DUMMY-TOKEN');
        Http::preventStrayRequests();
        Http::fake(['https://api.github.com/user' => Http::response(['id' => 91, 'login' => 'octocat', 'email' => 'not-retained@example.test'])]);

        $this->postJson('/connections', ['provider' => 'github', 'label' => 'Work', 'token' => 'DISTINCT-DUMMY-TOKEN'])->assertOk()->assertExactJson(['saved' => true]);

        $connection = ProviderConnection::sole();
        $this->assertSame('native-ciphertext', $connection->encrypted_token);
        $this->assertArrayNotHasKey('encrypted_token', $connection->toArray());
        $this->get('/settings/connections')->assertInertia(fn (Assert $page) => $page->where('connections.0.login', 'octocat')->missing('connections.0.encrypted_token'));
        $this->assertStringNotContainsString('DISTINCT-DUMMY-TOKEN', json_encode(session()->all()));
        $this->assertDatabaseHas('credential_access_events', ['connection_id' => $connection->id, 'operation' => 'create', 'result' => 'Succeeded']);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer DISTINCT-DUMMY-TOKEN') && $request->hasHeader('X-GitHub-Api-Version', '2026-03-10'));
        $this->from('/settings/connections')->post('/connections', ['provider' => 'invalid', 'token' => 'DISTINCT-DUMMY-TOKEN'])->assertSessionHasErrors('provider')->assertSessionMissing('_old_input.token');
    }

    public function test_failed_replacement_and_stale_writes_preserve_working_credentials(): void
    {
        $connection = ProviderConnection::factory()->create();
        $this->mock(ProtectCredential::class, function ($mock): void {
            $mock->shouldReceive('encrypt')->once()->andReturn('replacement-ciphertext');
            $mock->shouldReceive('decrypt')->once()->andReturn('new-token');
        });
        Http::preventStrayRequests();
        Http::fake(['https://api.github.com/user' => Http::response(['message' => 'new-token'], 401)]);

        $this->putJson('/connections/'.$connection->id, ['provider' => 'github', 'label' => 'Changed', 'token' => 'new-token', 'revision' => 1])
            ->assertUnprocessable()->assertJsonPath('message', 'Token required')->assertJsonPath('errors.connection', 'Token required');
        $this->assertSame('fixture-ciphertext', $connection->fresh()->encrypted_token);
        $this->putJson('/connections/'.$connection->id, ['provider' => 'github', 'label' => 'Changed', 'token' => 'new-token', 'revision' => 2])->assertConflict();
        Http::assertSentCount(1);
    }

    public function test_disconnect_clears_private_data_and_retains_local_records(): void
    {
        $connection = ProviderConnection::factory()->create();
        $repository = Repository::factory()->create(['provider_connection_id' => $connection->id, 'provider_repository_id' => '12', 'remote_commit_at' => now()]);
        ProviderSnapshot::factory()->for($repository)->create(['payload' => ['items' => [['id' => '1', 'title' => 'Private']]]]);

        $this->deleteJson('/connections/'.$connection->id, ['revision' => 2])->assertConflict();
        $this->deleteJson('/connections/'.$connection->id, ['revision' => 1])->assertOk();

        $this->assertModelMissing($connection);
        $this->assertModelExists($repository);
        $this->assertSame(2, $repository->fresh()->provider_revision);
        $this->assertNull($repository->fresh()->remote_commit_at);
        $this->assertDatabaseCount('provider_snapshots', 0);
    }

    public function test_verified_replacement_increments_generation_and_clears_old_private_pages(): void
    {
        $connection = ProviderConnection::factory()->create()->fresh();
        $repository = Repository::factory()->for($connection, 'providerConnection')->create();
        ProviderSnapshot::factory()->for($repository)->create(['payload' => ['items' => [['id' => '1', 'title' => 'Old private data']]]]);
        $this->mock(ProtectCredential::class, function ($mock): void {
            $mock->shouldReceive('encrypt')->once()->with('replacement')->andReturn('new-ciphertext');
            $mock->shouldReceive('decrypt')->once()->with('new-ciphertext')->andReturn('replacement');
        });
        Http::preventStrayRequests();
        Http::fake(['https://api.github.com/user' => Http::response(['id' => 123, 'login' => 'renamed-account'])]);

        $this->putJson('/connections/'.$connection->id, ['provider' => 'github', 'label' => 'Updated', 'revision' => 1, 'token' => 'replacement'])->assertOk();

        $this->assertSame('new-ciphertext', $connection->fresh()->encrypted_token);
        $this->assertSame(2, $connection->fresh()->revision);
        $this->assertSame(2, $repository->fresh()->provider_revision);
        $this->assertDatabaseCount('provider_snapshots', 0);
        Http::assertSentCount(1);
    }

    public function test_gitlab_identity_uses_private_token_header_and_multiple_connections_are_independent(): void
    {
        ProviderConnection::factory()->create(['label' => 'GitHub work']);
        $this->mock(ProtectCredential::class, function ($mock): void {
            $mock->shouldReceive('encrypt')->once()->andReturn('gitlab-ciphertext');
            $mock->shouldReceive('decrypt')->once()->andReturn('gitlab-dummy');
        });
        Http::preventStrayRequests();
        Http::fake(['https://gitlab.com/api/v4/user' => Http::response(['id' => 456, 'username' => 'gitlab-user'])]);

        $this->postJson('/connections', ['provider' => 'gitlab', 'label' => 'GitLab', 'token' => 'gitlab-dummy'])->assertOk();

        $this->assertDatabaseCount('provider_connections', 2);
        $this->assertDatabaseHas('provider_connections', ['provider' => 'gitlab', 'login' => 'gitlab-user', 'encrypted_token' => 'gitlab-ciphertext']);
        Http::assertSent(fn ($request) => $request->hasHeader('PRIVATE-TOKEN', 'gitlab-dummy') && ! $request->hasHeader('Authorization'));
    }
}
