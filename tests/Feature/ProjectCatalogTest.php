<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectFolder;
use App\Models\ProjectLink;
use App\Models\Repository;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Native\Desktop\Dialog;
use Native\Desktop\Facades\Shell;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProjectCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_persists_multiple_repositories_checkouts_tags_and_ordered_links(): void
    {
        Storage::fake('local');
        $first = $this->folder('first');
        $second = $this->folder('second');
        $plain = $this->folder('plain');
        $repo = (string) Str::uuid();
        $checkout = (string) Str::uuid();
        $payload = [
            'name' => 'Catalog project', 'description' => 'My workspace', 'status' => 'Active',
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
        $older = Project::factory()->create(['name' => 'Alpha 100%', 'description' => 'Backend', 'status' => 'Active']);
        $newer = Project::factory()->create(['name' => 'Zulu', 'description' => 'Frontend', 'status' => 'Paused']);
        $unknown = Project::factory()->create(['name' => 'No commit']);
        $tag = Tag::factory()->create(['name' => 'php']);
        $older->tags()->attach($tag);
        ProjectFolder::factory()->for($older)->create(['last_commit_at' => '2026-01-01 10:00:00']);
        ProjectFolder::factory()->for($newer)->create(['last_commit_at' => '2026-02-01 10:00:00']);

        $this->get('/?q=PHP&status=Active&tag=php')->assertInertia(fn (Assert $page) => $page
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
        $input = ['name' => 'Changed', 'status' => 'Active', 'revision' => 1];
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
            $mock->shouldReceive('folders->title->button->asSheet->open')->once()->andReturn(null);
        });
        $this->postJson('/folders/inspect')->assertExactJson(['folder' => null]);
        $this->assertDatabaseCount('project_folders', 0);
    }

    public function test_icons_are_served_privately_and_rejected_uploads_preserve_the_previous_image(): void
    {
        Storage::fake('local');
        $this->post('/projects', ['name' => 'Picture', 'status' => 'Idea', 'icon_type' => 'image',
            'icon_file' => UploadedFile::fake()->image('icon.png', 64, 64)])->assertRedirect();
        $project = Project::sole();
        $path = $project->icon_path;
        Storage::disk('local')->assertExists($path);
        $this->get('/projects/'.$project->id.'/icon')->assertOk()->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->post('/projects/'.$project->id, ['_method' => 'PUT', 'name' => 'Picture', 'status' => 'Idea', 'revision' => 1,
            'icon_type' => 'image', 'icon_file' => UploadedFile::fake()->createWithContent('bad.svg', '<svg/>')])
            ->assertSessionHasErrors('icon_file');
        $this->assertSame($path, $project->fresh()->icon_path);
        $this->put('/projects/'.$project->id, ['name' => 'Picture', 'status' => 'Idea', 'revision' => 1, 'icon_type' => 'initials'])->assertRedirect();
        Storage::disk('local')->assertMissing($path);
        $this->get('/projects/'.$project->id.'/icon')->assertNotFound();
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
