<?php

namespace Tests\Feature;

use App\Actions\ProtectCredential;
use App\Jobs\RefreshProviderResource;
use App\Models\Project;
use App\Models\ProjectFolder;
use App\Models\ProjectLink;
use App\Models\ProviderConnection;
use App\Models\Repository;
use App\Models\Tag;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Native\Desktop\Dialog;
use Native\Desktop\Facades\Shell;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProjectCatalogTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['/', 'Live'])]
    #[TestWith(['/?q=project&status=Idea&tag[]=php&page=2', 'Archived'])]
    #[TestWith(['/projects/another?tab=board', 'Paused'])]
    public function test_status_menu_updates_return_to_the_current_page(string $source, string $status): void
    {
        $project = Project::factory()->create();

        $this->from($source)->withHeaders(['X-Inertia' => 'true'])->put('/projects/'.$project->id, [
            'name' => $project->name, 'description' => $project->description,
            'status' => $status, 'revision' => 1, 'return_back' => true,
        ])->assertRedirect($source);

        $this->assertDatabaseHas('projects', [
            'id' => $project->id, 'status' => $status, 'revision' => 2,
            'name' => $project->name, 'description' => $project->description,
        ]);
    }

    public function test_saving_the_edit_form_redirects_to_the_saved_project(): void
    {
        $project = Project::factory()->create();

        $this->from('/projects/'.$project->id.'/edit')->put('/projects/'.$project->id, [
            'name' => 'Renamed project', 'status' => 'Live', 'revision' => 1,
        ])->assertRedirect('/projects/'.$project->id);

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'name' => 'Renamed project', 'status' => 'Live', 'revision' => 2]);
    }

    #[TestWith([[], 'https://example.com'])]
    #[TestWith([['label' => null], 'https://example.com'])]
    #[TestWith([['label' => ''], 'https://example.com'])]
    #[TestWith([['label' => '  '], 'https://example.com'])]
    #[TestWith([['label' => 'Docs'], 'Docs'])]
    #[TestWith([['label' => '0'], '0'])]
    public function test_optional_link_labels_default_to_the_url_without_replacing_custom_labels(array $labelInput, string $expectedLabel): void
    {
        $linkId = (string) Str::uuid();

        $this->post('/projects', ['name' => 'Project', 'status' => 'Idea', 'links' => [[
            'id' => $linkId, 'url' => 'https://example.com', ...$labelInput,
        ]]])->assertRedirect();

        $this->assertDatabaseHas('project_links', ['id' => $linkId, 'label' => $expectedLabel, 'url' => 'https://example.com']);
    }

    public function test_clearing_a_link_label_uses_the_complete_url_and_can_be_saved_again(): void
    {
        $project = Project::factory()->create();
        $link = ProjectLink::factory()->for($project)->create(['label' => 'Docs']);
        $url = 'https://example.com/'.str_repeat('a', 2028);
        $payload = ['name' => $project->name, 'status' => $project->status, 'revision' => 1, 'links' => [[
            'id' => $link->id, 'label' => '', 'url' => $url,
        ]]];

        $this->put('/projects/'.$project->id, $payload)->assertRedirect('/projects/'.$project->id);

        $this->assertDatabaseHas('project_links', ['id' => $link->id, 'label' => $url, 'url' => $url]);

        $payload['revision'] = 2;
        $payload['links'][0]['label'] = $url;
        $this->put('/projects/'.$project->id, $payload)->assertRedirect('/projects/'.$project->id);

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'revision' => 3]);
    }

    public function test_creating_project_with_selected_github_repository_verifies_and_queues_it(): void
    {
        $connection = ProviderConnection::factory()->create();
        $repositoryId = (string) Str::uuid();
        $this->mock(ProtectCredential::class, fn ($mock) => $mock->shouldReceive('decrypt')->once()->with('fixture-ciphertext')->andReturn('dummy-provider-token'));
        Queue::fake([RefreshProviderResource::class]);
        Http::preventStrayRequests();
        Http::fake(['https://api.github.com/repos/team/repo' => Http::response([
            'id' => 42, 'full_name' => 'team/repo', 'default_branch' => 'main', 'html_url' => 'https://github.com/team/repo',
        ])]);

        $this->post('/projects', ['name' => 'GitHub project', 'status' => 'Idea', 'repositories' => [[
            'id' => $repositoryId, 'name' => 'Repository', 'remote_url' => 'https://github.com/team/repo.git',
            'provider_connection_id' => $connection->id, 'provider_full_name' => 'team/repo',
        ]]])->assertRedirect();

        $this->assertDatabaseHas('repositories', ['id' => $repositoryId, 'remote_url' => 'https://github.com/team/repo.git',
            'provider_connection_id' => $connection->id, 'provider_repository_id' => '42', 'provider_name' => 'team/repo',
            'default_branch' => 'main', 'provider_url' => 'https://github.com/team/repo']);
        $this->assertDatabaseHas('provider_snapshots', ['repository_id' => $repositoryId, 'resource' => 'overview', 'state' => 'Queued']);
        Queue::assertPushed(RefreshProviderResource::class, 1);
        Http::assertSentCount(1);

        $project = Project::sole();
        $this->put('/projects/'.$project->id, ['name' => 'Renamed project', 'status' => 'Idea', 'revision' => 1, 'repositories' => [[
            'id' => $repositoryId, 'name' => 'Repository', 'remote_url' => 'https://github.com/team/repo.git', 'provider_connection_id' => $connection->id,
        ]]])->assertRedirect();
        $this->assertSame($connection->id, Repository::findOrFail($repositoryId)->provider_connection_id);
    }

    public function test_create_page_includes_gitlab_connections(): void
    {
        $connection = ProviderConnection::factory()->create(['provider' => 'gitlab']);

        $this->get('/projects/create')->assertInertia(fn (Assert $page) => $page
            ->has('connections', 1)->where('connections.0.id', $connection->id));
    }

    public function test_edit_page_includes_connected_github_and_gitlab_accounts(): void
    {
        $project = Project::factory()->create();
        $github = ProviderConnection::factory()->create(['label' => 'GitHub work']);
        $gitlab = ProviderConnection::factory()->create(['provider' => 'gitlab', 'label' => 'GitLab work']);

        $this->get('/projects/'.$project->id.'/edit')->assertInertia(fn (Assert $page) => $page
            ->component('EditProject')->has('connections', 2)
            ->where('connections.0.id', $github->id)->where('connections.1.id', $gitlab->id)
            ->missing('connections.0.encrypted_token')->missing('connections.1.encrypted_token'));
    }

    #[TestWith(['github', 'https://api.github.com/repos/team/repo', 'https://github.com/team/repo', 'full_name', 'html_url'])]
    #[TestWith(['gitlab', 'https://gitlab.com/api/v4/projects/team%2Frepo', 'https://gitlab.com/team/repo', 'path_with_namespace', 'web_url'])]
    public function test_editing_project_adds_a_verified_connected_repository_without_reconnecting_saved_repositories(string $provider, string $endpoint, string $webUrl, string $nameKey, string $urlKey): void
    {
        $project = Project::factory()->create();
        $savedConnection = ProviderConnection::factory()->create();
        $savedRepository = Repository::factory()->for($project)->for($savedConnection, 'providerConnection')->create();
        $connection = ProviderConnection::factory()->create(['provider' => $provider]);
        $repositoryId = (string) Str::uuid();
        $this->mock(ProtectCredential::class, fn ($mock) => $mock->shouldReceive('decrypt')->once()->andReturn('dummy-provider-token'));
        Queue::fake([RefreshProviderResource::class]);
        Http::preventStrayRequests();
        Http::fake([$endpoint => Http::response([
            'id' => 42, $nameKey => 'team/repo', 'default_branch' => 'main', $urlKey => $webUrl,
        ])]);

        $this->put('/projects/'.$project->id, ['name' => $project->name, 'status' => 'Idea', 'revision' => 1, 'repositories' => [
            ['id' => $savedRepository->id, 'remote_url' => $savedRepository->remote_url, 'provider_connection_id' => $connection->id, 'provider_full_name' => 'tampered/repository'],
            ['id' => $repositoryId, 'remote_url' => $webUrl.'.git', 'provider_connection_id' => $connection->id, 'provider_full_name' => 'team/repo'],
        ]])->assertRedirect('/projects/'.$project->id);

        $this->assertDatabaseHas('repositories', ['id' => $repositoryId, 'project_id' => $project->id,
            'provider_connection_id' => $connection->id, 'provider_repository_id' => '42', 'provider_name' => 'team/repo']);
        $this->assertDatabaseHas('repositories', ['id' => $savedRepository->id, 'provider_connection_id' => $savedConnection->id]);
        Queue::assertPushed(RefreshProviderResource::class, 1);
        Http::assertSentCount(1);
    }

    public function test_editing_project_rejects_a_mismatched_selected_remote_without_saving_changes(): void
    {
        $project = Project::factory()->create(['name' => 'Original']);
        $connection = ProviderConnection::factory()->create();
        $this->mock(ProtectCredential::class, fn ($mock) => $mock->shouldReceive('decrypt')->once()->andReturn('dummy-provider-token'));
        Queue::fake([RefreshProviderResource::class]);
        Http::preventStrayRequests();
        Http::fake(['https://api.github.com/repos/team/repo' => Http::response([
            'id' => 42, 'full_name' => 'team/repo', 'default_branch' => 'main', 'html_url' => 'https://github.com/team/repo',
        ])]);

        $this->putJson('/projects/'.$project->id, ['name' => 'Changed', 'status' => 'Idea', 'revision' => 1, 'repositories' => [[
            'id' => (string) Str::uuid(), 'remote_url' => 'https://github.com/other/repo.git',
            'provider_connection_id' => $connection->id, 'provider_full_name' => 'team/repo',
        ]]])->assertUnprocessable()->assertJsonValidationErrors(['repositories.0.remote_url' => 'The remote URL does not match the selected repository.']);

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'name' => 'Original', 'revision' => 1]);
        $this->assertDatabaseCount('repositories', 0);
        Queue::assertNotPushed(RefreshProviderResource::class);
        Http::assertSentCount(1);
    }

    public function test_creating_project_with_selected_gitlab_repository_verifies_and_queues_it(): void
    {
        $connection = ProviderConnection::factory()->create(['provider' => 'gitlab']);
        $repositoryId = (string) Str::uuid();
        $this->mock(ProtectCredential::class, fn ($mock) => $mock->shouldReceive('decrypt')->once()->andReturn('dummy-provider-token'));
        Queue::fake([RefreshProviderResource::class]);
        Http::preventStrayRequests();
        Http::fake(['https://gitlab.com/api/v4/projects/group%2Fteam%2Frepo' => Http::response([
            'id' => 42, 'path_with_namespace' => 'group/team/repo', 'default_branch' => 'main', 'web_url' => 'https://gitlab.com/group/team/repo',
        ])]);

        $this->post('/projects', ['name' => 'GitLab project', 'status' => 'Idea', 'repositories' => [[
            'id' => $repositoryId, 'name' => 'Repository', 'remote_url' => 'https://gitlab.com/group/team/repo.git',
            'provider_connection_id' => $connection->id, 'provider_full_name' => 'group/team/repo',
        ]]])->assertRedirect();

        $this->assertDatabaseHas('repositories', ['id' => $repositoryId, 'remote_url' => 'https://gitlab.com/group/team/repo.git',
            'provider_connection_id' => $connection->id, 'provider_repository_id' => '42', 'provider_name' => 'group/team/repo',
            'default_branch' => 'main', 'provider_url' => 'https://gitlab.com/group/team/repo']);
        Queue::assertPushed(RefreshProviderResource::class, 1);
        Http::assertSentCount(1);
    }

    public function test_creating_project_rejects_missing_connection_and_mismatched_remote(): void
    {
        $connection = ProviderConnection::factory()->create();
        Queue::fake([RefreshProviderResource::class]);
        Http::preventStrayRequests();

        $this->postJson('/projects', ['name' => 'Invalid project', 'status' => 'Idea', 'repositories' => [[
            'id' => (string) Str::uuid(), 'remote_url' => 'https://github.com/team/repo.git',
            'provider_connection_id' => (string) Str::uuid(), 'provider_full_name' => 'team/repo',
        ]]])->assertUnprocessable()->assertJsonValidationErrors(['repositories.0.provider_connection_id' => 'Choose a saved Git connection.']);
        $this->assertDatabaseCount('projects', 0);
        Queue::assertNotPushed(RefreshProviderResource::class);
        Http::assertNothingSent();

        $this->mock(ProtectCredential::class, fn ($mock) => $mock->shouldReceive('decrypt')->once()->andReturn('dummy-provider-token'));
        Http::fake(['https://api.github.com/repos/team/repo' => Http::response([
            'id' => 42, 'full_name' => 'team/repo', 'default_branch' => 'main', 'html_url' => 'https://github.com/team/repo',
        ])]);

        $this->postJson('/projects', ['name' => 'Wrong remote', 'status' => 'Idea', 'repositories' => [[
            'id' => (string) Str::uuid(), 'remote_url' => 'https://github.com/other/repo.git',
            'provider_connection_id' => $connection->id, 'provider_full_name' => 'team/repo',
        ]]])->assertUnprocessable()->assertJsonValidationErrors(['repositories.0.remote_url' => 'The remote URL does not match the selected repository.']);
        $this->assertDatabaseCount('projects', 0);
        Queue::assertNotPushed(RefreshProviderResource::class);
    }

    public function test_creating_project_rejects_github_token_failure_without_partial_project(): void
    {
        $connection = ProviderConnection::factory()->create();
        $this->mock(ProtectCredential::class, fn ($mock) => $mock->shouldReceive('decrypt')->once()->andReturn('dummy-provider-token'));
        Queue::fake([RefreshProviderResource::class]);
        Http::preventStrayRequests();
        Http::fake(['https://api.github.com/repos/team/repo' => Http::response(['message' => 'Bad credentials'], 401)]);

        $this->postJson('/projects', ['name' => 'Unavailable project', 'status' => 'Idea', 'repositories' => [[
            'id' => (string) Str::uuid(), 'remote_url' => 'https://github.com/team/repo.git',
            'provider_connection_id' => $connection->id, 'provider_full_name' => 'team/repo',
        ]]])->assertUnprocessable()->assertJsonValidationErrors(['repositories.0.provider_full_name' => 'Token required']);

        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('repositories', 0);
        $this->assertSame('Token required', $connection->fresh()->state);
        Queue::assertNotPushed(RefreshProviderResource::class);
    }

    public function test_catalog_persists_multiple_repositories_checkouts_tags_and_ordered_links(): void
    {
        Storage::fake('local');
        $first = $this->folder('first');
        $second = $this->folder('second');
        $plain = $this->folder('plain');
        $repo = (string) Str::uuid();
        $checkout = (string) Str::uuid();
        $payload = [
            'name' => 'Catalog project', 'description' => 'My workspace', 'status' => 'Live',
            'icon_type' => 'emoji', 'icon_emoji' => '🪐', 'tags' => [' PHP ', 'php', 'Vue'],
            'repositories' => [
                ['id' => $repo, 'name' => 'App', 'remote_url' => 'git@github.com:example/app.git'],
                ['id' => (string) Str::uuid(), 'name' => 'Docs', 'remote_url' => 'https://gitlab.com/example/docs.git'],
            ],
            'folders' => [
                ['id' => $checkout, 'path' => $first, 'repository_id' => $repo],
                ['id' => (string) Str::uuid(), 'path' => $second, 'repository_id' => $repo],
                ['id' => (string) Str::uuid(), 'path' => $plain, 'repository_id' => null],
            ],
            'links' => [
                ['id' => (string) Str::uuid(), 'label' => 'Site', 'url' => 'https://example.com', 'category' => 'Website', 'icon' => '🌐'],
                ['id' => (string) Str::uuid(), 'label' => 'Mail', 'url' => 'mailto:hello@example.com', 'category' => 'Inbox'],
            ],
        ];

        $this->post('/projects', $payload)->assertRedirect();
        $project = Project::sole();
        $this->get('/projects/'.$project->id)->assertInertia(fn (Assert $page) => $page
            ->has('selectedProject.repositories', 2)->has('selectedProject.folders', 3)->has('selectedProject.links', 2)
            ->where('sidebarProjects.0.description', 'My workspace')->where('sidebarProjects.0.revision', 1)
            ->where('selectedProject.tags.0.name', 'php')->where('selectedProject.tags.1.name', 'vue')
            ->where('selectedProject.icon_emoji', '🪐')->where('selectedProject.links.0.label', 'Site'));
        $this->assertDatabaseHas('project_folders', ['id' => $checkout, 'repository_id' => $repo, 'git_state' => 'Not a Git repository']);
        $this->assertNotNull(ProjectFolder::find($checkout)->scanned_at);

        File::moveDirectory($first, $first.'-moved');
        $this->get('/projects/'.$project->id)->assertInertia(fn (Assert $page) => $page
            ->where('selectedProject.folders.0.availability', 'Missing folder'));
        $payload['folders'][0]['path'] = $first.'-moved';
        $payload['links'] = array_reverse($payload['links']);
        $payload['revision'] = 1;
        $this->put('/projects/'.$project->id, $payload)->assertRedirect();
        $this->assertDatabaseHas('project_folders', ['id' => $checkout, 'path' => $first.'-moved', 'repository_id' => $repo]);
        $this->assertSame('Mail', $project->links()->first()->label);
        $this->assertSame(['php', 'vue'], $project->tags()->pluck('name')->all());

        $payload['tags'] = ['stale'];
        $this->put('/projects/'.$project->id, $payload)->assertConflict();
        $this->assertDatabaseMissing('tags', ['name' => 'stale']);
        $this->assertSame(2, $project->fresh()->revision);
    }

    public function test_search_filters_and_pagination_preserve_literal_search_and_sort_by_known_commit(): void
    {
        $older = Project::factory()->create(['name' => 'Alpha 100%', 'description' => 'Backend', 'status' => 'Live']);
        $newer = Project::factory()->create(['name' => 'Zulu', 'description' => 'Frontend', 'status' => 'Paused']);
        $unknown = Project::factory()->create(['name' => 'No commit']);
        $tag = Tag::factory()->create(['name' => 'php']);
        $older->tags()->attach($tag);
        ProjectFolder::factory()->for($older)->create(['last_commit_at' => '2026-01-01 10:00:00']);
        ProjectFolder::factory()->for($newer)->create(['last_commit_at' => '2026-02-01 10:00:00']);

        $this->get('/?q=PHP&status=Live&tag=php')->assertInertia(fn (Assert $page) => $page
            ->has('projects.data', 1)->where('projects.data.0.id', $older->id));
        $this->get('/?q=%25')->assertInertia(fn (Assert $page) => $page->has('projects.data', 1));
        $this->get('/?q=frontend')->assertInertia(fn (Assert $page) => $page->where('projects.data.0.id', $newer->id));
        $this->get('/?sort=last-commit')->assertInertia(fn (Assert $page) => $page
            ->where('projects.data.0.id', $newer->id)->where('projects.data.2.id', $unknown->id)
            ->where('projects.data.0.last_commit_at', '2026-02-01T10:00:00.000000Z')
            ->where('projects.data.2.last_commit_at', null));
        $this->get('/?sort=name-desc')->assertInertia(fn (Assert $page) => $page->where('projects.data.0.id', $newer->id));
        $this->getJson('/?sort=name;drop%20table%20projects')->assertUnprocessable();
        Project::factory()->count(26)->create(['status' => 'Maintenance']);
        $this->get('/?status=Maintenance&sort=name')->assertInertia(fn (Assert $page) => $page
            ->where('projects.next_page_url', '/?status=Maintenance&sort=name&page=2'));
    }

    public function test_project_cards_include_board_counts_and_saved_dependency_findings_for_each_project(): void
    {
        Storage::fake('local');
        $project = Project::factory()->create(['name' => 'Alpha']);
        $other = Project::factory()->create(['name' => 'Beta']);
        $columns = $project->boardColumns()->get();
        Task::factory()->for($columns[0], 'column')->create();
        Task::factory()->for($columns[1], 'column')->count(2)->create();
        Task::factory()->for($columns[3], 'column')->create();
        Task::factory()->for($other->boardColumns()->where('name', 'To Do')->first(), 'column')->count(3)->create();
        $folder = ProjectFolder::factory()->for($project)->create(['path' => $this->folder('card-findings')]);
        $root = $folder->packageRoots()->first();
        $snapshot = ['fingerprint' => 'current', 'unsupported_lockfiles' => [], 'files' => [
            'package.json' => ['state' => 'Current', 'entries' => [['name' => 'vue', 'required' => '^3', 'scope' => 'Production']]],
            'package-lock.json' => ['state' => 'Current', 'entries' => [['name' => 'vue', 'version' => '3.5.0', 'location' => 'node_modules/vue', 'scope' => 'Production']]],
        ]];
        $outdated = ['fingerprint' => 'current', 'checked' => 1, 'unavailable' => 0, 'skipped' => 0,
            'packages' => [['name' => 'vue', 'ecosystem' => 'npm', 'current' => '3.5.0', 'latest' => '3.5.1']]];
        $security = [...$outdated, 'packages' => [['name' => 'vue', 'ecosystem' => 'npm', 'current' => '3.5.0',
            'advisories' => [['id' => 'GHSA-example', 'severity' => 'High']]]]];
        $root->forceFill(['scan_state' => 'Current', 'snapshot' => $snapshot, 'outdated' => $outdated, 'security' => $security])->save();

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('projects.data.0.id', $project->id)
            ->has('projects.data.0.board_columns', 4)
            ->where('projects.data.0.board_columns.0.tasks_count', 1)
            ->where('projects.data.0.board_columns.1.name', 'To Do')
            ->where('projects.data.0.board_columns.1.tasks_count', 2)
            ->where('projects.data.0.board_columns.2.tasks_count', 0)
            ->where('projects.data.0.board_columns.3.name', 'Done')
            ->where('projects.data.0.board_columns.3.tasks_count', 1)
            ->missing('projects.data.0.board_columns.0.tasks')
            ->has('projects.data.0.folders', 1)
            ->where('projects.data.0.folders.0.id', $folder->id)
            ->where('projects.data.0.folders.0.availability', 'Available')
            ->has('projects.data.0.folders.0.package_roots', 1)
            ->where('projects.data.0.folders.0.package_roots.0.scan_state', 'Current')
            ->where('projects.data.0.folders.0.package_roots.0.snapshot', $snapshot)
            ->where('projects.data.0.folders.0.package_roots.0.outdated', $outdated)
            ->where('projects.data.0.folders.0.package_roots.0.security', $security)
            ->where('projects.data.1.id', $other->id)
            ->where('projects.data.1.board_columns.0.tasks_count', 0)
            ->where('projects.data.1.board_columns.1.tasks_count', 3)
            ->has('projects.data.1.folders', 0));
    }

    public function test_tag_filter_matches_every_selected_tag_and_accepts_single_tag_links(): void
    {
        $php = Tag::factory()->create(['name' => 'php']);
        $vue = Tag::factory()->create(['name' => 'vue']);
        $both = Project::factory()->create(['name' => 'Both tags']);
        $phpOnly = Project::factory()->create(['name' => 'PHP only']);
        $both->tags()->attach([$php->id, $vue->id]);
        $phpOnly->tags()->attach($php);

        $this->get('/?tag[]=php&tag[]=vue')->assertInertia(fn (Assert $page) => $page
            ->has('projects.data', 1)->where('projects.data.0.id', $both->id)
            ->where('filters.tag', ['php', 'vue']));
        $this->get('/?tag=php')->assertInertia(fn (Assert $page) => $page
            ->has('projects.data', 2)->where('filters.tag', ['php']));
        $this->getJson('/?tag[]=php&tag[]=PHP')->assertUnprocessable();
    }

    public function test_archiving_and_unlinking_preserve_source_folders_and_repository_files(): void
    {
        Storage::fake('local');
        $path = $this->folder('source');
        File::put($path.'/keep.txt', 'source stays here');
        $project = Project::factory()->create(['status' => 'Paused']);
        $repo = Repository::factory()->for($project)->create();
        $folder = ProjectFolder::factory()->for($project)->create(['path' => $path, 'repository_id' => $repo->id]);
        $url = '/projects/'.$project->id;

        $this->put($url, ['name' => $project->name, 'status' => 'Archived', 'revision' => 1])->assertRedirect();
        $this->assertNotNull($project->fresh()->archived_at);
        $this->assertSame('Paused', $project->fresh()->previous_status);
        $this->get('/?status=Archived')->assertInertia(fn (Assert $page) => $page->where('projects.data.0.id', $project->id));
        $this->put($url, ['name' => $project->name, 'status' => 'Paused', 'revision' => 2, 'repositories' => []])->assertRedirect();
        $this->assertNull($folder->fresh()->repository_id);
        $this->assertNull($project->fresh()->archived_at);
        $this->put($url, ['name' => $project->name, 'status' => 'Paused', 'revision' => 3, 'folders' => []])->assertRedirect();
        $this->assertFileExists($path.'/keep.txt');
        $this->assertDatabaseCount('project_folders', 0);
        $this->assertDatabaseCount('repositories', 0);
    }

    public function test_records_and_checkout_associations_cannot_move_between_projects(): void
    {
        $project = Project::factory()->create();
        $foreign = Repository::factory()->create();
        $input = ['name' => 'Changed', 'status' => 'Live', 'revision' => 1];
        $this->put('/projects/'.$project->id, [...$input, 'repositories' => [[
            'id' => $foreign->id, 'name' => 'Hijacked', 'remote_url' => 'https://github.com/example/repo',
        ]]])->assertSessionHasErrors('repositories');
        $this->put('/projects/'.$project->id, [...$input, 'folders' => [[
            'id' => (string) Str::uuid(), 'path' => '/tmp', 'repository_id' => $foreign->id,
        ]]])->assertSessionHasErrors('folders.0.repository_id');
        $this->assertSame(1, $project->fresh()->revision);
        $this->assertNotSame('Hijacked', $foreign->fresh()->name);
        $this->postJson('/projects/'.$project->id.'/open/repositories/'.$foreign->id)->assertNotFound();
    }

    public function test_removal_rejects_stale_revisions_and_preserves_source_files(): void
    {
        Storage::fake('local');
        $path = $this->folder('remove');
        File::put($path.'/keep.txt', 'Source');
        $project = Project::factory()->create();
        $repo = Repository::factory()->for($project)->create();
        ProjectFolder::factory()->for($project)->create(['path' => $path, 'repository_id' => $repo->id]);
        ProjectLink::factory()->for($project)->create();
        $project->tags()->attach(Tag::factory()->create());
        $this->delete('/projects/'.$project->id, ['revision' => 2])->assertConflict();
        $this->assertModelExists($project);
        $this->delete('/projects/'.$project->id, ['revision' => 1])->assertRedirect('/');
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('project_folders', 0);
        $this->assertDatabaseCount('repositories', 0);
        $this->assertDatabaseCount('project_links', 0);
        $this->assertDatabaseCount('project_tag', 0);
        $this->assertFileExists($path.'/keep.txt');
    }

    public function test_client_snapshots_and_private_icon_paths_are_not_trusted(): void
    {
        Storage::fake('local');
        $path = $this->folder('untrusted');
        $this->post('/projects', ['name' => 'Untrusted', 'status' => 'Idea', 'icon_path' => '/etc/passwd', 'folders' => [[
            'id' => (string) Str::uuid(), 'path' => $path, 'last_commit_at' => '2099-01-01',
            'git_state' => 'Git repository', 'scanned_at' => '2099-01-01',
        ]]])->assertRedirect();
        $project = Project::sole();
        $folder = $project->folders()->sole();
        $this->assertNull($project->icon_path);
        $this->assertNull($folder->last_commit_at);
        $this->assertSame('Not a Git repository', $folder->git_state);
        $this->put('/projects/'.$project->id, ['name' => $project->name, 'status' => 'Idea', 'revision' => 1, 'folders' => [[
            'id' => $folder->id, 'path' => $path, 'last_commit_at' => '2099-01-01',
        ]]])->assertRedirect();
        $this->assertNull($folder->fresh()->last_commit_at);
    }

    #[TestWith(['javascript:alert(1)'])]
    #[TestWith(['file:///etc/passwd'])]
    #[TestWith(['https://token:secret@example.com'])]
    #[TestWith(["https://example.com\nwrong"])]
    public function test_unsafe_link_destinations_are_rejected_without_creating_data(string $url): void
    {
        $this->post('/projects', ['name' => 'Unsafe', 'status' => 'Idea', 'links' => [[
            'id' => (string) Str::uuid(), 'label' => 'Link', 'url' => $url,
        ]]])->assertSessionHasErrors('links.0.url');
        $this->assertDatabaseCount('projects', 0);
    }

    public function test_folder_preview_detects_metadata_and_commit_time_without_creating_a_project(): void
    {
        Storage::fake('local');
        $path = $this->folder('preview');
        File::put($path.'/package.json', json_encode(['name' => 'Detected name', 'description' => 'Detected description']));
        foreach ([['init', '-b', 'main'], ['config', 'user.email', 'test@example.com'], ['config', 'user.name', 'Orbit Test'],
            ['remote', 'add', 'origin', 'git@github.com:example/catalog.git'], ['add', 'package.json'], ['commit', '-m', 'Initial']] as $command) {
            (new Process(['git', '-C', $path, ...$command], env: [
                'GIT_AUTHOR_DATE' => '2026-01-02T10:00:00Z', 'GIT_COMMITTER_DATE' => '2026-02-03T11:00:00Z',
            ]))->mustRun();
        }
        $this->postJson('/folders/inspect', ['path' => $path])->assertJsonPath('folder.name', 'preview')
            ->assertJsonPath('folder.description', 'Detected description')->assertJsonPath('folder.branch', 'main')
            ->assertJsonPath('folder.remote_url', 'git@github.com:example/catalog.git')
            ->assertJsonPath('folder.last_commit_at', '2026-02-03T11:00:00+00:00');
        $this->assertDatabaseCount('projects', 0);
        $this->postJson('/folders/inspect', ['path' => $path.'/missing'])->assertUnprocessable()->assertJsonValidationErrors('path');
        File::put($path.'/package.json', '{invalid');
        $this->postJson('/folders/inspect', ['path' => $path])->assertJsonPath('folder.warnings.0', 'package.json contains invalid JSON.');
    }

    public function test_cancelling_the_native_picker_does_not_create_or_change_records(): void
    {
        config(['nativephp-internal.running' => true]);
        $this->mock(Dialog::class, function ($mock) {
            $mock->shouldReceive('properties')->once()->with(['openDirectory', 'createDirectory'])->andReturnSelf();
            $mock->shouldReceive('title->button->asSheet->open')->once()->andReturn(null);
        });
        $this->postJson('/folders/inspect')->assertExactJson(['folder' => null]);
        $this->assertDatabaseCount('project_folders', 0);
    }

    public function test_icons_are_served_privately_and_rejected_uploads_preserve_the_previous_image(): void
    {
        Storage::fake('local');
        $this->post('/projects', ['name' => 'Picture', 'status' => 'Idea', 'icon_type' => 'image',
            'icon_file' => UploadedFile::fake()->image('icon.png', 64, 64)->size(5120)])->assertRedirect();
        $project = Project::sole();
        $path = $project->icon_path;
        Storage::disk('local')->assertExists($path);
        $this->get('/projects/'.$project->id.'/icon')->assertOk()->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->post('/projects/'.$project->id, ['_method' => 'PUT', 'name' => 'Picture', 'status' => 'Idea', 'revision' => 1,
            'icon_type' => 'image', 'icon_file' => UploadedFile::fake()->image('too-large.png', 64, 64)->size(5121)])
            ->assertSessionHasErrors('icon_file');
        $this->assertSame($path, $project->fresh()->icon_path);
        $this->assertSame(1, $project->fresh()->revision);
        $this->assertSame([$path], Storage::disk('local')->allFiles('project-icons'));
        $this->post('/projects/'.$project->id, ['_method' => 'PUT', 'name' => 'Picture', 'status' => 'Idea', 'revision' => 1,
            'icon_type' => 'image', 'icon_file' => UploadedFile::fake()->createWithContent('bad.svg', 'not an image')->mimeType('text/plain')])
            ->assertSessionHasErrors('icon_file');
        $this->assertSame($path, $project->fresh()->icon_path);
        $this->put('/projects/'.$project->id, ['name' => 'Picture', 'status' => 'Idea', 'revision' => 1, 'icon_type' => 'initials'])->assertRedirect();
        Storage::disk('local')->assertMissing($path);
        $this->get('/projects/'.$project->id.'/icon')->assertNotFound();
    }

    public function test_svg_icons_can_be_created_and_replaced_with_sandboxed_previews(): void
    {
        Storage::fake('local');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4096 4096"><rect width="4096" height="4096" fill="red"/></svg>';
        $this->post('/projects', ['name' => 'Vector', 'status' => 'Idea', 'icon_type' => 'image',
            'icon_file' => UploadedFile::fake()->createWithContent('icon.svg', $svg)->size(5120)])->assertRedirect()->assertSessionHasNoErrors();
        $project = Project::sole();
        $oldPath = $project->icon_path;
        $this->assertSame($svg, Storage::disk('local')->get($oldPath));

        $replacement = '<svg xmlns="http://www.w3.org/2000/svg" width="4096" height="4096"><style>rect { fill: blue; }</style><script>alert(1)</script><rect width="4096" height="4096"/></svg>';
        $this->post('/projects/'.$project->id, ['_method' => 'PUT', 'name' => 'Vector', 'status' => 'Idea', 'revision' => 1,
            'icon_type' => 'image', 'icon_file' => UploadedFile::fake()->createWithContent('replacement.svg', $replacement)])
            ->assertRedirect()->assertSessionHasNoErrors();
        $project->refresh();
        Storage::disk('local')->assertMissing($oldPath);
        $this->assertSame($replacement, Storage::disk('local')->get($project->icon_path));
        $this->get('/projects/'.$project->id.'/icon')->assertOk()->assertHeader('Content-Type', 'image/svg+xml')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");

        $this->post('/projects/'.$project->id, ['_method' => 'PUT', 'name' => 'Vector', 'status' => 'Idea', 'revision' => 2,
            'icon_type' => 'image', 'icon_file' => UploadedFile::fake()->createWithContent('too-large.svg', $svg)->size(5121)])
            ->assertSessionHasErrors('icon_file');
        $this->assertSame($project->icon_path, $project->fresh()->icon_path);
        $this->assertSame(2, $project->fresh()->revision);
        $this->assertSame([$project->icon_path], Storage::disk('local')->allFiles('project-icons'));
    }

    public function test_valid_https_links_open_in_the_external_browser(): void
    {
        config(['nativephp-internal.running' => true]);
        Shell::fake();
        $project = Project::factory()->create();

        foreach ([
            'https://github.com/team/repo/commit/'.str_repeat('a', 40),
            'https://gitlab.com/group/team/repo/-/commit/'.str_repeat('a', 40),
            'https://www.npmjs.com/package/%40orbit%2Fui/v/1.2.3',
            'https://packagist.org/packages/laravel/framework',
        ] as $url) {
            $this->postJson('/projects/'.$project->id.'/open-url', ['url' => $url])->assertExactJson(['opened' => true]);
            Shell::assertOpenedExternal($url);
        }
    }

    #[TestWith([null])]
    #[TestWith(['file:///tmp/repo'])]
    #[TestWith(['vscode://file/tmp/repo'])]
    #[TestWith(['https://user:password@github.com/team/repo/commit/abc'])]
    public function test_url_opener_rejects_missing_or_unsafe_urls(?string $url): void
    {
        config(['nativephp-internal.running' => true]);
        $shell = Shell::fake();
        $project = Project::factory()->create();

        $this->postJson('/projects/'.$project->id.'/open-url', ['url' => $url])
            ->assertUnprocessable()->assertJsonValidationErrors('url');

        $this->assertEmpty($shell->openExternalCalls);
    }

    public function test_url_opener_is_unavailable_outside_the_desktop_app(): void
    {
        config(['nativephp-internal.running' => false]);
        $shell = Shell::fake();
        $project = Project::factory()->create();

        $this->postJson('/projects/'.$project->id.'/open-url', ['url' => 'https://github.com/team/repo/commit/abc'])->assertForbidden();

        $this->assertEmpty($shell->openExternalCalls);
    }

    public function test_url_opener_reports_native_bridge_failures(): void
    {
        config(['nativephp-internal.running' => true]);
        $project = Project::factory()->create();
        Shell::shouldReceive('openExternal')->once()->andThrow(new RuntimeException('Bridge unavailable'));

        $this->postJson('/projects/'.$project->id.'/open-url', ['url' => 'https://github.com/team/repo/commit/abc'])
            ->assertServiceUnavailable()->assertExactJson(['message' => 'The URL could not be opened. Try again.']);
    }

    public function test_native_open_actions_use_only_the_selected_saved_target(): void
    {
        Storage::fake('local');
        Shell::fake();
        config(['nativephp-internal.running' => true]);
        $project = Project::factory()->create();
        $folder = ProjectFolder::factory()->for($project)->create(['path' => $this->folder('open')]);
        $link = ProjectLink::factory()->for($project)->create(['url' => 'https://example.com/catalog']);
        $this->postJson('/projects/'.$project->id.'/open/folders/'.$folder->id)->assertExactJson(['opened' => true]);
        $this->postJson('/projects/'.$project->id.'/open/links/'.$link->id)->assertExactJson(['opened' => true]);
        Shell::assertOpenedFile($folder->path);
        Shell::assertOpenedExternal('https://example.com/catalog');
        File::deleteDirectory($folder->path);
        $this->postJson('/projects/'.$project->id.'/open/folders/'.$folder->id)->assertUnprocessable()->assertJsonValidationErrors('target');
    }

    private function folder(string $name): string
    {
        $path = Storage::disk('local')->path('sources/'.$name);
        File::ensureDirectoryExists($path);

        return $path;
    }
}
