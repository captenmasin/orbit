<?php

namespace Tests\Feature;

use App\Actions\ProtectCredential;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\ProjectLink;
use App\Models\ProjectSecret;
use App\WorkspaceBackup;
use App\WorkspaceRestore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProjectKnowledgeRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_documents_review_date_and_secret_context_survive_backup_restore(): void
    {
        $project = Project::factory()->create(['reviewed_at' => '2026-09-01']);
        $body = "## Deployment\n\n```sh\n  php artisan migrate\n\n```\n";
        $document = ProjectDocument::factory()->for($project)->create(['title' => 'Hosting', 'body' => $body, 'position' => 0, 'revision' => 3]);
        ProjectDocument::factory()->for($project)->create(['title' => 'Database', 'position' => 1]);
        $secret = ProjectSecret::factory()->for($project)->create(['service' => 'Hosting', 'description' => 'Deployment token', 'management_url' => 'https://example.com/tokens', 'ciphertext' => 'source-ciphertext']);
        $crypto = $this->mock(ProtectCredential::class, function ($mock): void {
            $mock->shouldReceive('decrypt')->once()->with('source-ciphertext')->andReturn('dummy-value');
            $mock->shouldReceive('encrypt')->once()->with('dummy-value')->andReturn('restored-ciphertext');
        });
        $backup = app(WorkspaceBackup::class)->records(true, $crypto);

        app(WorkspaceRestore::class)->apply(app(WorkspaceRestore::class)->stage($backup, $crypto));

        $this->assertSame(['Hosting', 'Database'], $project->fresh()->documents->pluck('title')->all());
        $this->assertSame($body, $document->fresh()->body);
        $this->assertSame(3, $document->fresh()->revision);
        $this->assertSame('2026-09-01', $project->fresh()->reviewed_at->format('Y-m-d'));
        $this->assertDatabaseHas('project_secrets', ['id' => $secret->id, 'service' => 'Hosting', 'description' => 'Deployment token', 'management_url' => 'https://example.com/tokens', 'ciphertext' => 'restored-ciphertext']);
    }

    public function test_schema_one_notes_become_one_document_without_losing_whitespace(): void
    {
        $project = Project::factory()->create();
        $crypto = $this->mock(ProtectCredential::class, fn ($mock) => $mock->shouldNotReceive('decrypt')->shouldNotReceive('encrypt'));
        $backup = app(WorkspaceBackup::class)->records(false, $crypto);
        $backup[0]['data']['schema'] = 1;
        $body = "  Old notes\r\n\r\n```sh\r\necho hello\r\n```\r\n";
        foreach ($backup as &$record) {
            if ($record['type'] === 'projects') {
                $record['data']['notes'] = $body;
                unset($record['data']['reviewed_at']);
            }
        }
        unset($record);

        app(WorkspaceRestore::class)->apply(app(WorkspaceRestore::class)->stage($backup, $crypto));

        $this->assertNull($project->fresh()->notes);
        $this->assertNull($project->fresh()->reviewed_at);
        $this->assertSame($body, $project->fresh()->documents->sole()->body);
        $this->assertSame('Notes', $project->fresh()->documents->sole()->title);
    }

    public function test_schema_one_asset_folder_json_remains_compatible(): void
    {
        $folders = [['id' => '00000000-0000-4000-8000-000000000001', 'name' => 'Brand']];
        $project = Project::factory()->create(['asset_folders' => $folders]);
        $crypto = $this->mock(ProtectCredential::class, fn ($mock) => $mock->shouldNotReceive('decrypt')->shouldNotReceive('encrypt'));
        $backup = app(WorkspaceBackup::class)->records(false, $crypto);
        $backup[0]['data']['schema'] = 1;
        foreach ($backup as &$record) {
            if ($record['type'] === 'projects') {
                $record['data']['asset_folders'] = json_encode($folders, JSON_THROW_ON_ERROR);
            }
        }
        unset($record);

        app(WorkspaceRestore::class)->apply(app(WorkspaceRestore::class)->stage($backup, $crypto));

        $this->assertSame($folders, $project->fresh()->asset_folders);
    }

    public function test_nested_asset_folders_and_link_descriptions_survive_backup_restore(): void
    {
        $rootId = '00000000-0000-4000-8000-000000000001';
        $childId = '00000000-0000-4000-8000-000000000002';
        $folders = [
            ['id' => $rootId, 'name' => 'Brand', 'parent_id' => null],
            ['id' => $childId, 'name' => 'Brand', 'parent_id' => $rootId],
        ];
        $project = Project::factory()->create(['asset_folders' => $folders]);
        $link = ProjectLink::factory()->for($project)->create(['position' => 0, 'description' => '[Sign in](https://example.com/login)']);
        $crypto = $this->mock(ProtectCredential::class, fn ($mock) => $mock->shouldNotReceive('decrypt')->shouldNotReceive('encrypt'));

        app(WorkspaceRestore::class)->apply(app(WorkspaceRestore::class)->stage(app(WorkspaceBackup::class)->records(false, $crypto), $crypto));

        $this->assertSame($folders, $project->fresh()->asset_folders);
        $this->assertSame('[Sign in](https://example.com/login)', $link->fresh()->description);
    }

    #[DataProvider('invalidRecords')]
    public function test_invalid_knowledge_records_are_rejected_without_replacing_the_workspace(string $type, string $key, mixed $value): void
    {
        $project = Project::factory()->create(['name' => 'Keep me']);
        ProjectDocument::factory()->for($project)->create(['position' => 0]);
        ProjectSecret::factory()->for($project)->create();
        $crypto = $this->mock(ProtectCredential::class, function ($mock): void {
            $mock->shouldReceive('decrypt')->andReturn('dummy-value');
            $mock->shouldNotReceive('encrypt');
        });
        $backup = app(WorkspaceBackup::class)->records(true, $crypto);
        foreach ($backup as &$record) {
            if ($record['type'] === $type) {
                $record['data'][$key] = $value;
            }
        }
        unset($record);
        try {
            app(WorkspaceRestore::class)->stage($backup, $crypto);
            $this->fail('Invalid backup accepted.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseHas('projects', ['id' => $project->id, 'name' => 'Keep me']);
            $this->assertDatabaseCount('project_documents', 1);
        }
    }

    public static function invalidRecords(): array
    {
        return [
            'document ownership' => ['project_documents', 'project_id', '00000000-0000-4000-8000-000000000001'],
            'document order' => ['project_documents', 'position', 9],
            'document revision' => ['project_documents', 'revision', 0],
            'review date' => ['projects', 'reviewed_at', '2026-02-31'],
            'secret URL' => ['project_secrets', 'management_url', 'javascript:alert(1)'],
        ];
    }
}
