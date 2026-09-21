<?php

namespace Tests\Feature;

use App\Actions\ProtectCredential;
use App\Models\Project;
use App\WorkspaceBackup;
use App\WorkspaceRestore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProjectDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_have_separate_components_and_an_unfiltered_sidebar(): void
    {
        $project = Project::factory()->create(['name' => 'Find me']);
        Project::factory()->count(25)->create(['status' => 'Paused']);

        $this->get('/?q=Find%20me')->assertInertia(fn (Assert $page) => $page->component('Dashboard')
            ->has('projects.data', 1)->has('sidebarProjects', 26)->missing('sidebarProjects.0.notes'));
        $this->get('/projects/create')->assertInertia(fn (Assert $page) => $page->component('CreateProject')->has('sidebarProjects', 26));
        $this->get('/projects/'.$project->id)->assertInertia(fn (Assert $page) => $page->component('ShowProject')->where('selectedProject.id', $project->id));
        $this->get('/projects/'.$project->id.'/edit')->assertInertia(fn (Assert $page) => $page->component('EditProject'));
        $this->get('/settings/connections')->assertInertia(fn (Assert $page) => $page->component('Connections'));
        $this->get('/settings/backups')->assertInertia(fn (Assert $page) => $page->component('Backups'));
    }

    public function test_notes_are_saved_rendered_safely_and_protected_against_stale_edits(): void
    {
        $project = Project::factory()->create();
        $data = ['name' => $project->name, 'status' => $project->status, 'revision' => 1,
            'notes' => "## Setup\n\nKeep **this**. <script>alert(1)</script> [unsafe](javascript:alert(1))"];

        $this->put('/projects/'.$project->id, $data)->assertRedirect('/projects/'.$project->id);
        $this->assertSame($data['notes'], $project->fresh()->notes);
        $this->get('/projects/'.$project->id)->assertInertia(fn (Assert $page) => $page
            ->where('notesHtml', fn (string $html): bool => str_contains($html, '<h2>Setup</h2>') && ! str_contains($html, '<script>') && ! str_contains($html, 'href="javascript:')));
        $this->put('/projects/'.$project->id, [...$data, 'notes' => 'Stale overwrite'])->assertConflict();
        $this->assertSame($data['notes'], $project->fresh()->notes);
        $this->put('/projects/'.$project->id, ['name' => $project->name, 'status' => 'Paused', 'revision' => 2])->assertRedirect();
        $this->assertSame($data['notes'], $project->fresh()->notes);
    }

    public function test_sidebar_order_persists_and_rejects_incomplete_or_duplicate_lists(): void
    {
        $first = Project::factory()->create(['name' => 'Alpha']);
        $second = Project::factory()->create(['name' => 'Beta']);
        $this->from('/')->put('/projects/order', ['ids' => [$second->id, $first->id]])->assertRedirect('/');
        $this->get('/projects/'.$first->id)->assertInertia(fn (Assert $page) => $page
            ->where('sidebarProjects.0.id', $second->id)->where('sidebarProjects.1.id', $first->id));
        $this->assertSame(1, $first->fresh()->revision);

        $this->putJson('/projects/order', ['ids' => [$first->id]])->assertUnprocessable()->assertJsonValidationErrors('ids');
        $this->putJson('/projects/order', ['ids' => [$first->id, $first->id]])->assertUnprocessable();
        $this->putJson('/projects/order', ['ids' => [$first->id, (string) Str::uuid()]])->assertUnprocessable();
        $this->assertSame([$second->id, $first->id], Project::orderBy('position')->pluck('id')->all());
        $third = Project::factory()->create(['name' => 'A new project']);
        $this->assertSame([$second->id, $first->id, $third->id], Project::orderBy('position')->pluck('id')->all());
    }

    public function test_repository_names_are_inferred_and_link_emoji_input_is_ignored(): void
    {
        $this->post('/projects', ['name' => 'Orbit', 'status' => 'Idea', 'repositories' => [
            ['id' => (string) Str::uuid(), 'remote_url' => 'git@github.com:example/orbit.git'],
            ['id' => (string) Str::uuid(), 'name' => 'Custom name', 'remote_url' => 'https://github.com/example/docs'],
        ], 'links' => [['id' => (string) Str::uuid(), 'label' => 'Site', 'url' => 'https://example.com', 'icon' => 'not an emoji']]])->assertRedirect();

        $project = Project::sole();
        $this->assertSame(['Custom name', 'orbit'], $project->repositories()->pluck('name')->all());
        $this->assertNull($project->links()->sole()->icon);
    }

    public function test_assets_are_stored_privately_downloaded_only_for_their_project_and_removed(): void
    {
        Storage::fake('local');
        $project = Project::factory()->create();
        $other = Project::factory()->create();
        $this->post('/projects/'.$project->id.'/assets', ['revision' => 1, 'files' => [UploadedFile::fake()->createWithContent('design.txt', 'Project design')]])->assertRedirect('/projects/'.$project->id);
        $file = $project->fresh()->asset_files[0];
        Storage::disk('local')->assertExists($file['path']);
        $this->assertSame('Project design', Storage::disk('local')->get($file['path']));
        $this->get('/projects/'.$project->id)->assertInertia(fn (Assert $page) => $page
            ->missing('selectedProject.asset_files')->where('selectedProject.assets.0.name', 'design.txt')
            ->where('selectedProject.assets.0.mime_type', 'text/plain')->missing('selectedProject.assets.0.path'));
        $this->get('/projects/'.$project->id.'/assets/'.$file['id'])->assertDownload('design.txt')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/projects/'.$other->id.'/assets/'.$file['id'])->assertNotFound();
        $this->deleteJson('/projects/'.$other->id.'/assets/'.$file['id'], ['revision' => 1])->assertNotFound();
        $this->assertSame(1, $other->fresh()->revision);
        $this->deleteJson('/projects/'.$project->id.'/assets/'.$file['id'], ['revision' => 1])->assertUnprocessable()->assertJsonValidationErrors('revision');
        Storage::disk('local')->assertExists($file['path']);
        $this->delete('/projects/'.$project->id.'/assets/'.$file['id'], ['revision' => 2])->assertRedirect();
        Storage::disk('local')->assertMissing($file['path']);
        $this->assertSame([], $project->fresh()->asset_files);
    }

    public function test_rejected_uploads_do_not_change_assets_or_project_revision(): void
    {
        Storage::fake('local');
        $project = Project::factory()->create();
        $this->postJson('/projects/'.$project->id.'/assets', ['revision' => 1, 'files' => [UploadedFile::fake()->create('large.zip', 10241)]])->assertUnprocessable()->assertJsonValidationErrors('files.0');
        $this->postJson('/projects/'.$project->id.'/assets', ['revision' => 2, 'files' => [UploadedFile::fake()->createWithContent('stale.txt', 'stale')]])->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->assertSame(1, $project->fresh()->revision);
        $this->assertSame([], Storage::disk('local')->allFiles('project-assets'));
    }

    public function test_image_assets_have_private_inline_previews_scoped_to_their_project(): void
    {
        Storage::fake('local');
        $project = Project::factory()->create();
        $other = Project::factory()->create();
        $this->post('/projects/'.$project->id.'/assets', ['revision' => 1, 'files' => [UploadedFile::fake()->image('design.png', 64, 64)]])->assertRedirect();
        $file = $project->fresh()->asset_files[0];
        $previewUrl = '/projects/'.$project->id.'/assets/'.$file['id'].'/preview';

        $this->get('/projects/'.$project->id)->assertInertia(fn (Assert $page) => $page
            ->where('selectedProject.assets.0.preview_url', $previewUrl)->where('selectedProject.assets.0.mime_type', 'image/png')->missing('selectedProject.assets.0.path'));
        $this->get($previewUrl)->assertOk()->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Content-Disposition', 'inline')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/projects/'.$project->id.'/assets/'.$file['id'])->assertDownload('design.png');
        $this->get('/projects/'.$other->id.'/assets/'.$file['id'].'/preview')->assertNotFound();
        Storage::disk('local')->delete($file['path']);
        $this->get($previewUrl)->assertNotFound();
        $this->assertNull($project->fresh()->assets[0]['preview_url']);
        $this->assertNull($project->fresh()->assets[0]['mime_type']);
    }

    public function test_preview_uses_file_contents_and_rejects_html_disguised_as_an_image(): void
    {
        Storage::fake('local');
        $project = Project::factory()->create();
        $this->post('/projects/'.$project->id.'/assets', ['revision' => 1, 'files' => [
            UploadedFile::fake()->createWithContent('image.png', '<html><script>alert(1)</script></html>'),
        ]])->assertRedirect();
        $file = $project->fresh()->assets[0];

        $this->assertNull($file['preview_url']);
        $this->assertSame('text/html', $file['mime_type']);
        $this->get($file['url'].'/preview')->assertUnsupportedMediaType();
        $this->get($file['url'])->assertDownload('image.png')->assertHeader('Content-Type', 'application/octet-stream');
    }

    public function test_existing_and_restored_svg_assets_have_sandboxed_previews_without_new_metadata(): void
    {
        Storage::fake('local');
        $project = Project::factory()->create();
        $path = 'project-assets/restored/example';
        Storage::disk('local')->put($path, '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><script>alert(1)</script><rect width="64" height="64" fill="red"/></svg>');
        $project->forceFill(['asset_files' => [['id' => (string) Str::uuid(), 'name' => 'logo.svg', 'path' => $path, 'size' => Storage::disk('local')->size($path)]]])->save();

        $this->get('/projects/'.$project->id)->assertInertia(fn (Assert $page) => $page->where('selectedProject.assets.0.mime_type', 'image/svg+xml'));
        $this->get($project->assets[0]['preview_url'])->assertOk()->assertHeader('Content-Type', 'image/svg+xml')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
    }

    public function test_backups_restore_notes_order_and_asset_contents(): void
    {
        Storage::fake('local');
        $project = Project::factory()->create(['notes' => 'Project notes']);
        $project->forceFill(['position' => 7])->save();
        $this->post('/projects/'.$project->id.'/assets', ['revision' => 1, 'files' => [UploadedFile::fake()->createWithContent('notes.txt', 'Stored file')]])->assertRedirect();
        $oldPath = $project->fresh()->asset_files[0]['path'];
        $crypto = app(ProtectCredential::class);
        $staged = app(WorkspaceRestore::class)->stage(app(WorkspaceBackup::class)->records(false, $crypto), $crypto);
        app(WorkspaceRestore::class)->apply($staged);

        $restored = $project->fresh();
        $this->assertSame('Project notes', $restored->notes);
        $this->assertSame(7, $restored->position);
        $this->assertSame('Stored file', Storage::disk('local')->get($restored->asset_files[0]['path']));
        Storage::disk('local')->assertMissing($oldPath);
        $path = $restored->asset_files[0]['path'];
        $this->delete('/projects/'.$project->id, ['revision' => $restored->revision])->assertRedirect('/');
        Storage::disk('local')->assertMissing($path);
    }
}
