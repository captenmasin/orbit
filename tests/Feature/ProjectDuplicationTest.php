<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\ProjectLink;
use App\Models\ProjectSecret;
use App\Models\ProviderConnection;
use App\Models\ProviderSnapshot;
use App\Models\Repository;
use App\Models\Tag;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProjectDuplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_copies_user_content_and_keeps_files_after_the_source_is_removed(): void
    {
        Storage::fake('local');
        $source = Project::factory()->create([
            'name' => 'Orbit', 'description' => 'Project summary', 'status' => 'Archived',
            'archived_at' => now(), 'previous_status' => 'Live', 'icon_type' => 'image',
        ]);
        $source->tags()->attach(Tag::factory()->create(['name' => 'laravel']));
        $connection = ProviderConnection::factory()->create();
        $repository = Repository::factory()->for($source)->for($connection, 'providerConnection')->create([
            'provider_repository_id' => '42', 'provider_name' => 'team/orbit', 'default_branch' => 'main',
            'remote_commit_at' => now(), 'provider_revision' => 3,
        ]);
        ProviderSnapshot::factory()->for($repository)->create();
        $folder = $source->folders()->create(['path' => '/missing/orbit', 'repository_id' => $repository->id]);
        $folder->forceFill(['scan_state' => 'Queued', 'scan_token' => (string) Str::uuid(), 'last_commit_hash' => str_repeat('a', 40)])->save();
        $folder->packageRoots()->sole()->forceFill([
            'executable_overrides' => ['php' => '/custom/php'], 'snapshot' => ['old' => true], 'scan_state' => 'Current',
        ])->save();
        $folder->packageRoots()->create(['relative_path' => 'packages/web', 'executable_overrides' => ['node' => '/custom/node']]);
        ProjectLink::factory()->for($source)->create(['label' => 'Docs', 'position' => 2]);
        ProjectDocument::factory()->for($source)->create(['title' => 'Plan', 'body' => 'Keep this', 'position' => 0]);
        ProjectSecret::factory()->for($source)->create(['name' => 'API_TOKEN', 'ciphertext' => 'sealed-value']);
        $source->boardColumns()->delete();
        $column = $source->boardColumns()->create(['name' => 'Review', 'position' => 0]);
        $task = Task::factory()->for($column, 'column')->create(['title' => 'Ship it']);
        $attachmentPath = 'task-attachments/restored-source/note.txt';
        $assetPath = 'project-assets/restored-source/brief.txt';
        Storage::disk('local')->put($attachmentPath, 'Attachment content');
        Storage::disk('local')->put($assetPath, 'Asset content');
        $task->forceFill(['attachment_files' => [[
            'id' => (string) Str::uuid(), 'name' => 'note.txt', 'path' => $attachmentPath, 'size' => 18,
        ]]])->save();
        $iconPath = UploadedFile::fake()->image('icon.png')->store('project-icons', 'local');
        $iconContents = Storage::disk('local')->get($iconPath);
        $parentId = (string) Str::uuid();
        $childId = (string) Str::uuid();
        $source->forceFill([
            'icon_path' => $iconPath,
            'asset_folders' => [
                ['id' => $parentId, 'name' => 'Plans', 'parent_id' => null],
                ['id' => $childId, 'name' => 'Current', 'parent_id' => $parentId],
            ],
            'asset_files' => [[
                'id' => (string) Str::uuid(), 'name' => 'brief.txt', 'path' => $assetPath,
                'size' => 13, 'folder_id' => $childId,
            ]],
        ])->save();

        $response = $this->post('/projects/'.$source->id.'/duplicate');

        $duplicate = Project::where('id', '!=', $source->id)->sole();
        $response->assertRedirect('/projects/'.$duplicate->id)->assertSessionHas('message', 'Project duplicated');
        $this->assertSame('Orbit (copy)', $duplicate->name);
        $this->assertSame('Project summary', $duplicate->description);
        $this->assertSame('Archived', $duplicate->status);
        $this->assertSame('Live', $duplicate->previous_status);
        $this->assertNotNull($duplicate->archived_at);
        $this->assertSame(1, $duplicate->revision);
        $this->assertSame(['laravel'], $duplicate->tags()->pluck('name')->all());
        $repositoryCopy = $duplicate->repositories()->sole();
        $this->assertNotSame($repository->id, $repositoryCopy->id);
        $this->assertSame($connection->id, $repositoryCopy->provider_connection_id);
        $this->assertSame('42', $repositoryCopy->provider_repository_id);
        $this->assertSame(1, $repositoryCopy->provider_revision);
        $this->assertNull($repositoryCopy->remote_commit_at);
        $this->assertCount(0, $repositoryCopy->providerSnapshots);
        $folderCopy = $duplicate->folders()->sole();
        $this->assertNotSame($folder->id, $folderCopy->id);
        $this->assertSame($repositoryCopy->id, $folderCopy->repository_id);
        $this->assertSame('/missing/orbit', $folderCopy->path);
        $this->assertSame('Not scanned', $folderCopy->scan_state);
        $this->assertNull($folderCopy->scan_token);
        $this->assertNull($folderCopy->last_commit_hash);
        $roots = $folderCopy->packageRoots;
        $this->assertSame(['.', 'packages/web'], $roots->pluck('relative_path')->all());
        $this->assertSame(['php' => '/custom/php'], $roots[0]->executable_overrides);
        $this->assertSame(['node' => '/custom/node'], $roots[1]->executable_overrides);
        $this->assertNull($roots[0]->snapshot);
        $this->assertSame('Not scanned', $roots[0]->scan_state);
        $this->assertSame('Docs', $duplicate->links()->sole()->label);
        $this->assertNotSame($source->links()->sole()->id, $duplicate->links()->sole()->id);
        $this->assertSame('Keep this', $duplicate->documents()->sole()->body);
        $this->assertSame(1, $duplicate->documents()->sole()->revision);
        $this->assertSame('sealed-value', $duplicate->secrets()->sole()->ciphertext);
        $this->assertNotSame($source->secrets()->sole()->id, $duplicate->secrets()->sole()->id);
        $this->assertSame(1, $duplicate->secrets()->sole()->revision);
        $this->assertSame(['Review'], $duplicate->boardColumns()->pluck('name')->all());
        $taskCopy = $duplicate->boardColumns()->sole()->tasks()->sole();
        $this->assertNotSame($task->id, $taskCopy->id);
        $this->assertSame('Ship it', $taskCopy->title);
        $this->assertNotSame($attachmentPath, $taskCopy->attachment_files[0]['path']);
        $this->assertNotSame($task->attachment_files[0]['id'], $taskCopy->attachment_files[0]['id']);
        $this->assertNotSame($iconPath, $duplicate->icon_path);
        $this->assertNotSame($assetPath, $duplicate->asset_files[0]['path']);
        $this->assertNotSame($source->asset_files[0]['id'], $duplicate->asset_files[0]['id']);
        $this->assertNotSame($parentId, $duplicate->asset_folders[0]['id']);
        $this->assertSame($duplicate->asset_folders[0]['id'], $duplicate->asset_folders[1]['parent_id']);
        $this->assertSame($duplicate->asset_folders[1]['id'], $duplicate->asset_files[0]['folder_id']);

        $this->delete('/projects/'.$source->id, ['revision' => 1])->assertRedirect('/');
        Storage::disk('local')->assertExists($duplicate->icon_path);
        Storage::disk('local')->assertExists($duplicate->asset_files[0]['path']);
        Storage::disk('local')->assertExists($taskCopy->attachment_files[0]['path']);
        $this->assertSame($iconContents, Storage::disk('local')->get($duplicate->icon_path));
        $this->assertSame('Asset content', Storage::disk('local')->get($duplicate->asset_files[0]['path']));
        $this->assertSame('Attachment content', Storage::disk('local')->get($taskCopy->attachment_files[0]['path']));
        $this->assertSame(['laravel'], $duplicate->fresh()->tags()->pluck('name')->all());
        $this->get('/projects/'.$duplicate->id.'/icon')->assertOk();
        $this->get('/projects/'.$duplicate->id.'/assets/'.$duplicate->asset_files[0]['id'])->assertDownload('brief.txt');
        $this->get('/projects/'.$duplicate->id.'/tasks/'.$taskCopy->id.'/attachments/'.$taskCopy->attachment_files[0]['id'])->assertDownload('note.txt');
    }

    public function test_duplicate_truncates_long_names_and_preserves_empty_board_and_roots(): void
    {
        $source = Project::factory()->create(['name' => str_repeat('🪐', 254)]);
        $folder = $source->folders()->create(['path' => '/missing/empty']);
        $folder->packageRoots()->delete();
        $source->boardColumns()->delete();

        $response = $this->post('/projects/'.$source->id.'/duplicate');

        $duplicate = Project::where('id', '!=', $source->id)->sole();
        $response->assertRedirect('/projects/'.$duplicate->id);
        $this->assertSame(str_repeat('🪐', 248).' (copy)', $duplicate->name);
        $this->assertCount(0, $duplicate->boardColumns);
        $this->assertCount(0, $duplicate->folders()->sole()->packageRoots);
    }

    public function test_duplicate_rolls_back_database_and_copied_files_if_a_source_file_is_missing(): void
    {
        Storage::fake('local');
        $source = Project::factory()->create();
        $task = Task::factory()->for($source->boardColumns->first(), 'column')->create();
        $attachmentPath = 'task-attachments/'.$source->id.'/note.txt';
        Storage::disk('local')->put($attachmentPath, 'Original');
        $task->forceFill(['attachment_files' => [[
            'id' => (string) Str::uuid(), 'name' => 'note.txt', 'path' => $attachmentPath, 'size' => 8,
        ]]])->save();
        $source->forceFill(['asset_files' => [[
            'id' => (string) Str::uuid(), 'name' => 'missing.txt', 'path' => 'project-assets/missing.txt', 'size' => 1,
        ]]])->save();

        $this->post('/projects/'.$source->id.'/duplicate')->assertInternalServerError();

        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('tasks', 1);
        $this->assertSame([$attachmentPath], Storage::disk('local')->allFiles('task-attachments'));
        $this->assertSame([], Storage::disk('local')->allFiles('project-assets'));
    }
}
