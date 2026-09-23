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
use PHPUnit\Framework\Attributes\TestWith;
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
        $this->assertSame($data['notes'], $project->documents()->sole()->body);
        $this->assertNull($project->fresh()->notes);
        $this->get('/projects/'.$project->id)->assertInertia(fn (Assert $page) => $page
            ->where('selectedProject.documents.0.body_html', fn (string $html): bool => str_contains($html, '<h2>Setup</h2>') && ! str_contains($html, '<script>') && ! str_contains($html, 'href="javascript:')));
        $this->put('/projects/'.$project->id, [...$data, 'notes' => 'Stale overwrite'])->assertConflict();
        $this->assertSame($data['notes'], $project->documents()->sole()->body);
        $this->assertNull($project->fresh()->notes);
        $this->put('/projects/'.$project->id, ['name' => $project->name, 'status' => 'Paused', 'revision' => 2])->assertRedirect();
        $this->assertSame($data['notes'], $project->documents()->sole()->body);
        $this->assertNull($project->fresh()->notes);
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
        ], 'links' => [['id' => (string) Str::uuid(), 'label' => 'Site', 'url' => 'https://example.com', 'description' => '**Login:** account@example.com <script>alert(1)</script>', 'icon' => 'not an emoji']]])->assertRedirect();

        $project = Project::sole();
        $this->assertSame(['Custom name', 'orbit'], $project->repositories()->pluck('name')->all());
        $this->assertNull($project->links()->sole()->icon);
        $this->assertSame('**Login:** account@example.com <script>alert(1)</script>', $project->links()->sole()->description);
        $this->get('/projects/'.$project->id)->assertInertia(fn (Assert $page) => $page
            ->where('selectedProject.links.0.description_html', fn (string $html): bool => str_contains($html, '<strong>Login:</strong>') && ! str_contains($html, '<script>')));
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

    public function test_dropped_folders_preserve_nested_and_empty_directories_and_reuse_existing_folders(): void
    {
        Storage::fake('local');
        $project = Project::factory()->create();
        $url = '/projects/'.$project->id;
        $this->post($url.'/asset-folders', ['revision' => 1, 'name' => 'Brand'])->assertRedirect();
        $brandId = $project->fresh()->asset_folders[0]['id'];

        $this->post($url.'/assets', [
            'revision' => 2,
            'directories' => ['Brand', 'Brand/Icons', 'Brand/Empty'],
            'files' => [UploadedFile::fake()->createWithContent('logo.svg', '<svg/>')],
            'paths' => ['Brand/Icons/logo.svg'],
        ])->assertRedirect();

        $current = $project->fresh();
        $this->assertCount(3, $current->asset_folders);
        $this->assertSame($brandId, $current->asset_folders[0]['id']);
        $this->assertSame($brandId, $current->asset_folders[1]['parent_id']);
        $this->assertSame(['id' => $current->asset_folders[2]['id'], 'name' => 'Empty', 'parent_id' => $brandId], $current->asset_folders[2]);
        $this->assertSame($current->asset_folders[1]['id'], $current->asset_files[0]['folder_id']);
        Storage::disk('local')->assertExists($current->asset_files[0]['path']);

        $this->post($url.'/assets', ['revision' => 3, 'folder_id' => $brandId, 'directories' => ['EmptyOnly']])->assertRedirect();
        $this->assertCount(4, $project->fresh()->asset_folders);
        $this->assertSame($brandId, $project->fresh()->asset_folders[3]['parent_id']);
        $this->assertSame(4, $project->fresh()->revision);
    }

    public function test_dropped_folder_names_keep_their_original_spacing(): void
    {
        Storage::fake('local');
        $project = Project::factory()->create();

        $this->post('/projects/'.$project->id.'/assets', [
            'revision' => 1,
            'directories' => [' Brand '],
            'files' => [UploadedFile::fake()->createWithContent(' notes.txt ', 'Keep this name')],
            'paths' => [' Brand / notes.txt '],
        ])->assertRedirect();

        $current = $project->fresh();
        $this->assertSame(' Brand ', $current->asset_folders[0]['name']);
        $this->assertSame(' notes.txt ', $current->asset_files[0]['name']);
        $this->assertSame($current->asset_folders[0]['id'], $current->asset_files[0]['folder_id']);
        Storage::disk('local')->assertExists($current->asset_files[0]['path']);
    }

    public function test_a_folder_with_more_than_twenty_files_imports_in_one_drop(): void
    {
        Storage::fake('local');
        $project = Project::factory()->create();
        $numbers = range(1, 21);

        $this->post('/projects/'.$project->id.'/assets', [
            'revision' => 1,
            'directories' => ['Album'],
            'files' => array_map(fn (int $number): UploadedFile => UploadedFile::fake()->createWithContent('image-'.$number.'.txt', 'asset'), $numbers),
            'paths' => array_map(fn (int $number): string => 'Album/image-'.$number.'.txt', $numbers),
        ])->assertRedirect();

        $current = $project->fresh();
        $this->assertCount(21, $current->asset_files);
        $this->assertCount(1, $current->asset_folders);
        $this->assertSame($current->asset_folders[0]['id'], $current->asset_files[20]['folder_id']);
        $this->assertCount(21, Storage::disk('local')->allFiles('project-assets'));
    }

    public function test_invalid_dropped_folder_paths_leave_the_project_unchanged(): void
    {
        Storage::fake('local');
        $project = Project::factory()->create();
        $url = '/projects/'.$project->id.'/assets';
        $this->postJson($url, ['revision' => 1, 'files' => [UploadedFile::fake()->createWithContent('logo.txt', 'logo')], 'paths' => ['../logo.txt']])
            ->assertUnprocessable()->assertJsonValidationErrors('directories');
        $this->postJson($url, ['revision' => 1, 'directories' => ['Brand//Empty']])
            ->assertUnprocessable()->assertJsonValidationErrors('directories');
        $this->assertSame(1, $project->fresh()->revision);
        $this->assertSame([], $project->fresh()->asset_folders ?? []);
        $this->assertSame([], Storage::disk('local')->allFiles('project-assets'));
    }

    public function test_asset_folders_can_be_created_renamed_and_removed_without_losing_files(): void
    {
        Storage::fake('local');
        $project = Project::factory()->create();
        $url = '/projects/'.$project->id;
        $this->post($url.'/asset-folders', ['revision' => 1, 'name' => 'logo'])->assertRedirect($url);
        $this->post($url.'/asset-folders', ['revision' => 2, 'name' => 'screenshots'])->assertRedirect($url);
        [$logo, $screenshots] = $project->fresh()->asset_folders;
        $this->assertSame('logo', $logo['name']);

        $this->post($url.'/assets', ['revision' => 3, 'folder_id' => $logo['id'], 'files' => [UploadedFile::fake()->image('logo.png')]])->assertRedirect($url);
        $file = $project->fresh()->asset_files[0];
        $this->get($url)->assertInertia(fn (Assert $page) => $page
            ->where('selectedProject.asset_folders.0.name', 'logo')->where('selectedProject.assets.0.folder_id', $logo['id']));
        $this->put($url.'/asset-folders/'.$logo['id'], ['revision' => 4, 'name' => 'Brand'])->assertRedirect($url);
        $this->assertSame(['id' => $logo['id'], 'name' => 'Brand', 'parent_id' => null], $project->fresh()->asset_folders[0]);
        $this->assertSame($logo['id'], $project->fresh()->asset_files[0]['folder_id']);

        $this->put($url.'/assets/'.$file['id'], ['revision' => 5, 'folder_id' => $screenshots['id']])->assertRedirect($url);
        $this->assertSame($screenshots['id'], $project->fresh()->asset_files[0]['folder_id']);
        $this->put($url.'/assets/'.$file['id'], ['revision' => 6, 'folder_id' => null])->assertRedirect($url);
        $this->assertNull($project->fresh()->asset_files[0]['folder_id']);
        $this->put($url.'/assets/'.$file['id'], ['revision' => 7, 'folder_id' => $logo['id']])->assertRedirect($url);
        $this->delete($url.'/asset-folders/'.$logo['id'], ['revision' => 8])->assertRedirect($url);

        $this->assertSame([['id' => $screenshots['id'], 'name' => $screenshots['name'], 'parent_id' => null]], $project->fresh()->asset_folders);
        $this->assertSame([...$file, 'folder_id' => null], $project->fresh()->asset_files[0]);
        $this->assertSame(9, $project->fresh()->revision);
        Storage::disk('local')->assertExists($file['path']);
        $this->get($url.'/assets/'.$file['id'])->assertDownload('logo.png');
    }

    public function test_asset_folder_changes_reject_stale_revisions_and_other_projects_files_and_folders(): void
    {
        Storage::fake('local');
        $project = Project::factory()->create();
        $other = Project::factory()->create();
        $url = '/projects/'.$project->id;
        $this->post('/projects/'.$other->id.'/asset-folders', ['revision' => 1, 'name' => 'Private'])->assertRedirect();
        $foreignFolder = $other->fresh()->asset_folders[0]['id'];
        $this->post($url.'/asset-folders', ['revision' => 1, 'name' => 'logo'])->assertRedirect();
        $folder = $project->fresh()->asset_folders[0]['id'];
        $this->post($url.'/assets', ['revision' => 2, 'files' => [UploadedFile::fake()->createWithContent('logo.txt', 'asset')]])->assertRedirect();
        $file = $project->fresh()->asset_files[0];

        $this->putJson($url.'/assets/'.$file['id'], ['revision' => 3, 'folder_id' => $foreignFolder])->assertUnprocessable()->assertJsonValidationErrors('folder_id');
        $this->postJson($url.'/assets', ['revision' => 3, 'folder_id' => $foreignFolder, 'files' => [UploadedFile::fake()->createWithContent('other.txt', 'other')]])->assertUnprocessable()->assertJsonValidationErrors('folder_id');
        $this->putJson('/projects/'.$other->id.'/assets/'.$file['id'], ['revision' => 2, 'folder_id' => $foreignFolder])->assertNotFound();
        $this->putJson($url.'/asset-folders/'.$foreignFolder, ['revision' => 3, 'name' => 'Renamed'])->assertNotFound();
        $this->deleteJson($url.'/asset-folders/'.$foreignFolder, ['revision' => 3])->assertNotFound();
        $this->putJson($url.'/assets/'.$file['id'], ['revision' => 2, 'folder_id' => $folder])->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->putJson($url.'/asset-folders/'.$folder, ['revision' => 2, 'name' => 'Renamed'])->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->deleteJson($url.'/asset-folders/'.$folder, ['revision' => 2])->assertUnprocessable()->assertJsonValidationErrors('revision');

        $this->assertSame(3, $project->fresh()->revision);
        $this->assertSame(2, $other->fresh()->revision);
        $this->assertSame([$file], $project->fresh()->asset_files);
        $this->assertSame('logo', $project->fresh()->asset_folders[0]['name']);
        $this->assertSame([$file['path']], Storage::disk('local')->allFiles('project-assets'));
    }

    public function test_nested_asset_folders_keep_contents_when_removed_and_files_can_be_renamed(): void
    {
        Storage::fake('local');
        $project = Project::factory()->create();
        $url = '/projects/'.$project->id;
        $this->post($url.'/asset-folders', ['revision' => 1, 'name' => 'Design'])->assertRedirect();
        $parent = $project->fresh()->asset_folders[0]['id'];
        $this->post($url.'/asset-folders', ['revision' => 2, 'name' => 'Logos', 'parent_id' => $parent])->assertRedirect();
        $child = $project->fresh()->asset_folders[1]['id'];
        $this->post($url.'/assets', ['revision' => 3, 'folder_id' => $child, 'files' => [UploadedFile::fake()->createWithContent('old.txt', 'content')]])->assertRedirect();
        $file = $project->fresh()->asset_files[0];

        $this->putJson($url.'/asset-folders/'.$parent, ['revision' => 4, 'name' => 'Design', 'parent_id' => $child])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->put($url.'/assets/'.$file['id'], ['revision' => 4, 'folder_id' => $child, 'name' => 'new.txt'])->assertRedirect();
        $this->get($url.'/assets/'.$file['id'])->assertDownload('new.txt');
        $this->delete($url.'/asset-folders/'.$child, ['revision' => 5])->assertRedirect();
        $this->assertSame($parent, $project->fresh()->asset_files[0]['folder_id']);
        $this->assertSame('new.txt', $project->fresh()->asset_files[0]['name']);
        Storage::disk('local')->assertExists($file['path']);
    }

    public function test_removing_a_folder_rejects_conflicting_child_names_without_changing_the_tree(): void
    {
        $project = Project::factory()->create();
        $url = '/projects/'.$project->id;
        $this->post($url.'/asset-folders', ['revision' => 1, 'name' => 'Container'])->assertRedirect();
        $containerId = $project->fresh()->asset_folders[0]['id'];
        $this->post($url.'/asset-folders', ['revision' => 2, 'name' => 'Icons'])->assertRedirect();
        $this->post($url.'/asset-folders', ['revision' => 3, 'name' => 'Icons', 'parent_id' => $containerId])->assertRedirect();
        $folders = $project->fresh()->asset_folders;

        $this->deleteJson($url.'/asset-folders/'.$containerId, ['revision' => 4])
            ->assertUnprocessable()
            ->assertJsonPath('errors.folder.0', 'Rename or move the conflicting subfolder before removing this folder.');

        $this->assertSame(4, $project->fresh()->revision);
        $this->assertSame($folders, $project->fresh()->asset_folders);
    }

    #[TestWith([''])]
    #[TestWith(['../logo'])]
    #[TestWith(['logo\\small'])]
    #[TestWith(['..'])]
    #[TestWith(["bad\0name"])]
    #[TestWith([' LOGO '])]
    public function test_invalid_or_duplicate_asset_folder_names_leave_the_project_unchanged(string $name): void
    {
        $project = Project::factory()->create();
        $url = '/projects/'.$project->id.'/asset-folders';
        $this->post($url, ['revision' => 1, 'name' => 'logo'])->assertRedirect();

        $this->postJson($url, ['revision' => 2, 'name' => $name])->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->assertSame(2, $project->fresh()->revision);
        $this->assertCount(1, $project->fresh()->asset_folders);
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
        $folders = [['id' => (string) Str::uuid(), 'name' => 'logo'], ['id' => (string) Str::uuid(), 'name' => 'Empty']];
        $project->forceFill(['position' => 7, 'asset_folders' => $folders])->save();
        $this->post('/projects/'.$project->id.'/assets', ['revision' => 1, 'folder_id' => $folders[0]['id'], 'files' => [UploadedFile::fake()->createWithContent('notes.txt', 'Stored file')]])->assertRedirect();
        $oldPath = $project->fresh()->asset_files[0]['path'];
        $crypto = app(ProtectCredential::class);
        $staged = app(WorkspaceRestore::class)->stage(app(WorkspaceBackup::class)->records(false, $crypto), $crypto);
        app(WorkspaceRestore::class)->apply($staged);

        $restored = $project->fresh();
        $this->assertSame('Project notes', $restored->documents()->sole()->body);
        $this->assertNull($restored->notes);
        $this->assertSame(7, $restored->position);
        $this->assertSame($folders, $restored->asset_folders);
        $this->assertSame($folders[0]['id'], $restored->asset_files[0]['folder_id']);
        $this->assertSame('Stored file', Storage::disk('local')->get($restored->asset_files[0]['path']));
        Storage::disk('local')->assertMissing($oldPath);
        $path = $restored->asset_files[0]['path'];
        $this->delete('/projects/'.$project->id, ['revision' => $restored->revision])->assertRedirect('/');
        Storage::disk('local')->assertMissing($path);
    }
}
