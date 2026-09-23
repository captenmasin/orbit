<?php

namespace Tests\Feature;

use App\Actions\ProtectCredential;
use App\Models\Project;
use App\Models\ProjectSecret;
use App\Models\Task;
use App\WorkspaceBackup;
use App\WorkspaceRestore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkspaceRestoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stages_validated_records_and_reencrypts_portable_secrets(): void
    {
        $project = Project::factory()->create();
        ProjectSecret::factory()->for($project)->create(['ciphertext' => 'source-ciphertext']);
        $this->mock(ProtectCredential::class, function ($mock): void {
            $mock->shouldReceive('decrypt')->once()->with('source-ciphertext')->andReturn("multiline\nsecret");
            $mock->shouldReceive('encrypt')->once()->with("multiline\nsecret")->andReturn('destination-ciphertext');
        });

        $records = app(WorkspaceBackup::class)->records(true, app(ProtectCredential::class));
        $staged = app(WorkspaceRestore::class)->stage($records, app(ProtectCredential::class));
        $secret = collect($staged['records'])->firstWhere('type', 'project_secrets')['data'];

        $this->assertSame(1, $staged['summary']['projects']);
        $this->assertSame(1, $staged['summary']['secrets']);
        $this->assertSame('destination-ciphertext', $secret['ciphertext']);
        $this->assertArrayNotHasKey('value', $secret);
    }

    public function test_it_rejects_a_backup_with_an_invalid_relationship(): void
    {
        $project = Project::factory()->create();
        Task::factory()->for($project->boardColumns()->first(), 'column')->create();
        $crypto = $this->mock(ProtectCredential::class, fn ($mock) => $mock->shouldNotReceive('decrypt')->shouldNotReceive('encrypt'));
        $records = app(WorkspaceBackup::class)->records(false, $crypto);
        foreach ($records as &$record) {
            if ($record['type'] === 'tasks') {
                $record['data']['board_column_id'] = '09ad2de3-bffb-4a53-a4f2-2c6b2749cfc5';
                break;
            }
        }
        unset($record);

        $this->expectException(InvalidArgumentException::class);
        app(WorkspaceRestore::class)->stage($records, $crypto);
    }

    public function test_it_replaces_workspace_records_and_restores_private_assets(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('project-icons/source.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl3Gx8AAAAASUVORK5CYII='));
        $project = Project::factory()->create(['icon_type' => 'image', 'icon_path' => 'project-icons/source.png']);
        $column = $project->boardColumns()->first();
        Storage::disk('local')->put('task-attachments/'.$project->id.'/notes.txt', 'notes');
        $task = Task::factory()->for($column, 'column')->create(['attachment_files' => [[
            'id' => '09ad2de3-bffb-4a53-a4f2-2c6b2749cfc5', 'name' => 'notes.txt', 'path' => 'task-attachments/'.$project->id.'/notes.txt', 'size' => 5,
        ]]]);
        $crypto = $this->mock(ProtectCredential::class, fn ($mock) => $mock->shouldNotReceive('decrypt')->shouldNotReceive('encrypt'));
        $staged = app(WorkspaceRestore::class)->stage(app(WorkspaceBackup::class)->records(false, $crypto), $crypto);
        Project::factory()->create(['name' => 'To be replaced']);

        app(WorkspaceRestore::class)->apply($staged);

        $restored = Project::findOrFail($project->id);
        $this->assertSame(1, Project::count());
        $this->assertNotSame('project-icons/source.png', $restored->icon_path);
        Storage::disk('local')->assertExists($restored->icon_path);
        $this->assertSame('notes', Storage::disk('local')->get(Task::findOrFail($task->id)->attachment_files[0]['path']));
    }

    #[DataProvider('unreferencedAssets')]
    public function test_it_rejects_extra_assets_before_writing_files_or_replacing_records(string $assetId): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('protected.png', 'keep this file');
        $project = Project::factory()->create(['name' => 'Keep this project']);
        $crypto = $this->mock(ProtectCredential::class, fn ($mock) => $mock->shouldNotReceive('decrypt')->shouldNotReceive('encrypt'));
        $records = app(WorkspaceBackup::class)->records(false, $crypto);
        $records[] = ['type' => 'asset', 'data' => [
            'id' => $assetId, 'chunk' => 0, 'chunks' => 1, 'mime' => 'image/png', 'content' => base64_encode('untrusted contents'),
        ]];

        try {
            $restore = app(WorkspaceRestore::class);
            $restore->apply($restore->stage($records, $crypto));
            $this->fail('Unreferenced backup asset accepted.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseHas('projects', ['id' => $project->id, 'name' => 'Keep this project']);
            $this->assertSame('keep this file', Storage::disk('local')->get('protected.png'));
            $this->assertSame(['protected.png'], Storage::disk('local')->allFiles());
        }
    }

    public static function unreferencedAssets(): array
    {
        return [
            'path traversal' => ['icon:../../protected'],
            'unowned icon' => ['icon:00000000-0000-4000-8000-000000000001'],
            'unowned attachment' => ['attachment:00000000-0000-4000-8000-000000000001:00000000-0000-4000-8000-000000000002'],
        ];
    }
}
