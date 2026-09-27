<?php

namespace Tests\Feature;

use App\Actions\CloneRepository;
use App\Actions\ProtectCredential;
use App\Models\ProviderConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Native\Desktop\Dialog;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RepositoryCloneTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_clone_checks_out_files_without_storing_its_credential_in_origin(): void
    {
        Storage::fake('local');
        $source = Storage::path('source.git');
        $parent = Storage::path('checkouts');
        File::ensureDirectoryExists($source);
        File::ensureDirectoryExists($parent);
        $this->git($source, ['init', '-b', 'main']);
        File::put($source.'/README.md', 'Cloned content');
        $this->git($source, ['add', 'README.md']);
        $this->git($source, ['commit', '-m', 'Initial commit']);
        $remote = 'file://'.$source;

        $folder = app(CloneRepository::class)->handle($remote, $parent, 'github', 'DUMMY-PRIVATE-TOKEN');

        $this->assertSame($parent.'/source', $folder['path']);
        $this->assertSame('Git repository', $folder['git_state']);
        $this->assertSame('Initial commit', $folder['commit_subject']);
        $this->assertSame('Cloned content', File::get($folder['path'].'/README.md'));
        $this->assertSame($remote, $this->git($folder['path'], ['remote', 'get-url', 'origin']));
        $this->assertStringNotContainsString('DUMMY-PRIVATE-TOKEN', File::get($folder['path'].'/.git/config'));
    }

    public function test_failed_clone_removes_its_partial_folder_and_preserves_other_files(): void
    {
        Storage::fake('local');
        $parent = Storage::path('checkouts');
        File::ensureDirectoryExists($parent);
        File::put($parent.'/keep.txt', 'Keep me');

        try {
            app(CloneRepository::class)->handle('file://'.$parent.'/missing.git', $parent, 'github', 'DUMMY-PRIVATE-TOKEN');
            $this->fail('Clone should fail for a missing source.');
        } catch (ValidationException $exception) {
            $this->assertSame(['repository' => ['Repository could not be cloned. Check this connection and try again.']], $exception->errors());
        }

        $this->assertFalse(file_exists($parent.'/missing'));
        $this->assertSame('Keep me', File::get($parent.'/keep.txt'));
    }

    public function test_browser_clone_request_returns_422_without_reading_a_connection(): void
    {
        Http::preventStrayRequests();

        $this->postJson('/repositories/clone', ['connection_id' => '7a7618fd-19b3-4bf0-ae15-68a9b267ae42', 'full_name' => 'team/repo'])
            ->assertUnprocessable()->assertJsonValidationErrors(['connection' => 'Clone repositories from the desktop app.']);

        Http::assertNothingSent();
        $this->assertDatabaseCount('projects', 0);
    }

    public function test_cancelled_gitlab_clone_returns_no_folder_without_creating_a_project(): void
    {
        config(['nativephp-internal.running' => true]);
        $connection = ProviderConnection::factory()->create(['provider' => 'gitlab']);
        $this->mock(ProtectCredential::class, fn ($mock) => $mock->shouldReceive('decrypt')->once()->andReturn('DUMMY-PRIVATE-TOKEN'));
        Http::preventStrayRequests();
        Http::fake(['https://gitlab.com/api/v4/projects/*' => Http::response([
            'id' => 42, 'path_with_namespace' => 'group/repo', 'default_branch' => 'main', 'web_url' => 'https://gitlab.com/group/repo',
        ])]);
        $this->mock(Dialog::class, fn ($mock) => $mock->shouldReceive('folders->title->button->asSheet->open')->once()->andReturn(null));

        $this->postJson('/repositories/clone', ['connection_id' => $connection->id, 'full_name' => 'group/repo'])
            ->assertExactJson(['folder' => null]);

        Http::assertSentCount(1);
        $this->assertDatabaseCount('projects', 0);
    }

    public function test_verified_gitlab_repository_returns_cloned_folder_without_creating_a_project(): void
    {
        Storage::fake('local');
        config(['nativephp-internal.running' => true]);
        $parent = Storage::path('checkouts');
        File::ensureDirectoryExists($parent);
        $connection = ProviderConnection::factory()->create(['provider' => 'gitlab']);
        $this->mock(ProtectCredential::class, fn ($mock) => $mock->shouldReceive('decrypt')->twice()->andReturn('DUMMY-PRIVATE-TOKEN'));
        Http::preventStrayRequests();
        Http::fake(['https://gitlab.com/api/v4/projects/*' => Http::response([
            'id' => 42, 'path_with_namespace' => 'group/repo', 'default_branch' => 'main', 'web_url' => 'https://gitlab.com/group/repo',
        ])]);
        $this->mock(Dialog::class, fn ($mock) => $mock->shouldReceive('folders->title->button->asSheet->open')->once()->andReturn($parent));
        $preview = ['path' => $parent.'/repo', 'name' => 'repo', 'remote_url' => 'https://gitlab.com/group/repo.git'];
        $this->mock(CloneRepository::class, fn ($mock) => $mock->shouldReceive('handle')->once()
            ->with('https://gitlab.com/group/repo.git', $parent, 'gitlab', 'DUMMY-PRIVATE-TOKEN')->andReturn($preview));

        $this->postJson('/repositories/clone', ['connection_id' => $connection->id, 'full_name' => 'group/repo'])
            ->assertExactJson(['folder' => $preview]);

        Http::assertSentCount(1);
        $this->assertDatabaseCount('projects', 0);
    }

    public function test_existing_destination_returns_422_and_preserves_its_files(): void
    {
        Storage::fake('local');
        config(['nativephp-internal.running' => true]);
        $parent = Storage::path('checkouts');
        File::ensureDirectoryExists($parent.'/repo');
        File::put($parent.'/repo/keep.txt', 'Keep me');
        $connection = ProviderConnection::factory()->create();
        $this->mock(ProtectCredential::class, fn ($mock) => $mock->shouldReceive('decrypt')->twice()->andReturn('DUMMY-PRIVATE-TOKEN'));
        Http::preventStrayRequests();
        Http::fake(['https://api.github.com/repos/team/repo' => Http::response([
            'id' => 42, 'full_name' => 'team/repo', 'default_branch' => 'main', 'html_url' => 'https://github.com/team/repo',
        ])]);
        $this->mock(Dialog::class, fn ($mock) => $mock->shouldReceive('folders->title->button->asSheet->open')->once()->andReturn($parent));

        $this->postJson('/repositories/clone', ['connection_id' => $connection->id, 'full_name' => 'team/repo'])
            ->assertUnprocessable()->assertJsonValidationErrors(['repository' => 'A folder with this repository name already exists.']);

        $this->assertSame('Keep me', File::get($parent.'/repo/keep.txt'));
        $this->assertDatabaseCount('projects', 0);
    }

    public function test_changed_provider_repository_returns_422_before_opening_folder_picker(): void
    {
        config(['nativephp-internal.running' => true]);
        $connection = ProviderConnection::factory()->create();
        $this->mock(ProtectCredential::class, fn ($mock) => $mock->shouldReceive('decrypt')->once()->andReturn('DUMMY-PRIVATE-TOKEN'));
        Http::preventStrayRequests();
        Http::fake(['https://api.github.com/repos/team/repo' => Http::response([
            'id' => 42, 'full_name' => 'other/repo', 'default_branch' => 'main', 'html_url' => 'https://github.com/other/repo',
        ])]);
        $this->mock(Dialog::class, fn ($mock) => $mock->shouldReceive('folders')->never());

        $this->postJson('/repositories/clone', ['connection_id' => $connection->id, 'full_name' => 'team/repo'])
            ->assertUnprocessable()->assertJsonValidationErrors(['repository' => 'Provider returned a different repository. Choose it again.']);

        $this->assertDatabaseCount('projects', 0);
    }

    private function git(string $path, array $arguments): string
    {
        $process = new Process(['git', '-C', $path, '-c', 'user.name=Orbit Test', '-c', 'user.email=orbit@example.test', '-c', 'commit.gpgsign=false', ...$arguments], env: [
            'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1',
        ]);
        $process->mustRun();

        return trim($process->getOutput());
    }
}
