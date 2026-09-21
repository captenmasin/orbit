<?php

namespace Tests\Feature;

use App\Actions\ProtectCredential;
use App\Models\BoardColumn;
use App\Models\Project;
use App\Models\ProjectSecret;
use App\Models\Tag;
use App\Models\Task;
use App\WorkspaceBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkspaceBackupTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_collects_portable_workspace_records_without_provider_credentials(): void
    {
        $project = Project::factory()->create();
        $tag = Tag::factory()->create();
        $project->tags()->attach($tag);
        $secret = ProjectSecret::factory()->for($project)->create(['ciphertext' => 'native-ciphertext']);
        $this->mock(ProtectCredential::class, function ($mock): void {
            $mock->shouldReceive('decrypt')->once()->with('native-ciphertext')->andReturn("multiline\nsecret");
        });

        $records = app(WorkspaceBackup::class)->records(true, app(ProtectCredential::class));
        $secretRecord = collect($records)->firstWhere('type', 'project_secrets');

        $this->assertSame($project->id, collect($records)->firstWhere('type', 'projects')['data']['id']);
        $this->assertSame($tag->id, collect($records)->firstWhere('type', 'project_tag')['data']['tag_id']);
        $this->assertSame("multiline\nsecret", $secretRecord['data']['value']);
        $this->assertArrayNotHasKey('ciphertext', $secretRecord['data']);
        $this->assertSame($secret->id, $secretRecord['data']['id']);
    }

    public function test_it_omits_secrets_without_decrypting_them_when_requested(): void
    {
        ProjectSecret::factory()->create();
        $this->mock(ProtectCredential::class, fn ($mock) => $mock->shouldNotReceive('decrypt'));

        $records = app(WorkspaceBackup::class)->records(false, app(ProtectCredential::class));

        $this->assertNull(collect($records)->firstWhere('type', 'project_secrets'));
    }

    public function test_it_packages_icons_and_attachments_without_scan_or_provider_cache_data(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('project-icons/orbit.png', 'icon');
        $project = Project::factory()->create(['icon_type' => 'image', 'icon_path' => 'project-icons/orbit.png']);
        $column = BoardColumn::factory()->for($project)->create();
        Storage::disk('local')->put('task-attachments/'.$project->id.'/notes.txt', 'notes');
        $task = Task::factory()->for($column, 'column')->create(['attachment_files' => [[
            'id' => '09ad2de3-bffb-4a53-a4f2-2c6b2749cfc5', 'name' => 'notes.txt', 'path' => 'task-attachments/'.$project->id.'/notes.txt', 'size' => 5,
        ]]]);

        $records = app(WorkspaceBackup::class)->records(false, app(ProtectCredential::class));
        $projectRecord = collect($records)->firstWhere('type', 'projects')['data'];
        $taskRecord = collect($records)->first(fn (array $record): bool => $record['type'] === 'tasks' && $record['data']['id'] === $task->id)['data'];
        $assets = collect($records)->where('type', 'asset')->pluck('data')->keyBy('id');

        $this->assertNull($projectRecord['icon_path']);
        $this->assertSame('aWNvbg==', $assets['icon:'.$project->id]['content']);
        $this->assertSame('bm90ZXM=', $assets['attachment:'.$task->id.':09ad2de3-bffb-4a53-a4f2-2c6b2749cfc5']['content']);
        $this->assertArrayNotHasKey('path', $taskRecord['attachment_files'][0]);
        $this->assertArrayNotHasKey('scan_state', collect($records)->firstWhere('type', 'project_folders')['data'] ?? []);
        $this->assertArrayNotHasKey('provider_connection_id', collect($records)->firstWhere('type', 'repositories')['data'] ?? []);
    }
}
