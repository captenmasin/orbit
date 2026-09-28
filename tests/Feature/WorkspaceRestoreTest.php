<?php

namespace Tests\Feature;

use App\Actions\ProtectCredential;
use App\Models\BoardColumn;
use App\Models\Project;
use App\Models\ProjectFolder;
use App\Models\ProjectSecret;
use App\Models\Task;
use App\WorkspaceBackup;
use App\WorkspacePreferences;
use App\WorkspaceRestore;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
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

    #[TestWith(['C:/outside'])]
    #[TestWith(['C:\\outside'])]
    #[TestWith(['\\\\server\\share'])]
    #[TestWith(['packages\\..\\outside'])]
    #[TestWith(["packages/web\0"])]
    public function test_it_rejects_windows_absolute_or_unsafe_relative_package_roots(string $path): void
    {
        ProjectFolder::factory()->create();
        $crypto = app(ProtectCredential::class);
        $records = app(WorkspaceBackup::class)->records(false, $crypto);
        foreach ($records as &$record) {
            if ($record['type'] === 'package_roots') {
                $record['data']['relative_path'] = $path;
                break;
            }
        }
        unset($record);

        $this->expectException(InvalidArgumentException::class);
        app(WorkspaceRestore::class)->stage($records, $crypto);
    }

    public function test_it_restores_board_defaults_and_preserves_local_preferences(): void
    {
        $preferences = app(WorkspacePreferences::class);
        $preferences->merge(['project_defaults' => ['columns' => [['name' => 'Incoming', 'color' => 'blue']]]]);
        $crypto = app(ProtectCredential::class);
        $records = app(WorkspaceBackup::class)->records(false, $crypto);
        $preferences->merge([
            'project_defaults' => ['columns' => [['name' => 'Current', 'color' => null]]],
            'backups' => ['folder' => '/local/backups'],
            'tools' => ['paths' => ['php' => '/local/php']],
            'appearance' => ['theme' => 'dark'],
        ]);
        $restore = app(WorkspaceRestore::class);
        $staged = $restore->stage($records, $crypto);

        $restore->apply($staged);

        $this->assertSame(['columns' => [['name' => 'Incoming', 'color' => 'blue']]], $staged['summary']['project_defaults']);
        $this->assertSame([['name' => 'Incoming', 'color' => 'blue']], $preferences->get('project_defaults.columns'));
        $this->assertSame('/local/backups', $preferences->get('backups.folder'));
        $this->assertSame('/local/php', $preferences->get('tools.paths.php'));
        $this->assertSame('dark', $preferences->get('appearance.theme'));
    }

    #[DataProvider('legacySchemas')]
    public function test_legacy_backups_keep_the_installations_board_defaults(int $schema): void
    {
        $preferences = app(WorkspacePreferences::class);
        $preferences->merge(['project_defaults' => ['columns' => [['name' => 'Local', 'color' => 'green']]]]);
        $project = Project::factory()->create();
        $crypto = app(ProtectCredential::class);
        $records = array_values(array_filter(app(WorkspaceBackup::class)->records(false, $crypto), fn (array $record): bool => $record['type'] !== 'preferences'));
        $records[0]['data']['schema'] = $schema;
        $restore = app(WorkspaceRestore::class);
        $staged = $restore->stage($records, $crypto);

        $restore->apply($staged);

        $this->assertNull($staged['summary']['project_defaults']);
        $this->assertSame([['name' => 'Local', 'color' => 'green']], $preferences->get('project_defaults.columns'));
        $this->assertSame(['Local'], $project->fresh()->boardColumns->pluck('name')->all());
    }

    public static function legacySchemas(): array
    {
        return [[1], [2], [3]];
    }

    #[DataProvider('invalidPreferences')]
    public function test_it_rejects_invalid_portable_preferences_before_restoring(array $incoming): void
    {
        $project = Project::factory()->create();
        $preferences = app(WorkspacePreferences::class);
        $snapshot = $preferences->snapshot();
        $crypto = app(ProtectCredential::class);
        $records = app(WorkspaceBackup::class)->records(false, $crypto);
        $records[1]['data'] = [...$incoming, 'project_statuses' => WorkspacePreferences::defaults()['project_statuses']];

        try {
            app(WorkspaceRestore::class)->stage($records, $crypto);
            $this->fail('Invalid portable preferences accepted.');
        } catch (InvalidArgumentException) {
            $this->assertSame($snapshot, $preferences->snapshot());
            $this->assertModelExists($project);
        }
    }

    public static function invalidPreferences(): array
    {
        return [
            'local settings' => [['project_defaults' => ['columns' => [['name' => 'Ready', 'color' => null]]], 'backups' => ['folder' => '/incoming/path']]],
            'empty board' => [['project_defaults' => ['columns' => []]]],
            'invalid color' => [['project_defaults' => ['columns' => [['name' => 'Ready', 'color' => 'rainbow']]]]],
            'blank name' => [['project_defaults' => ['columns' => [['name' => ' ', 'color' => null]]]]],
            'long name' => [['project_defaults' => ['columns' => [['name' => str_repeat('a', 101), 'color' => null]]]]],
            'project column identifier' => [['project_defaults' => ['columns' => [['id' => 'local-id', 'name' => 'Ready', 'color' => null]]]]],
            'excess columns' => [['project_defaults' => ['columns' => array_fill(0, 101, ['name' => 'Ready', 'color' => null])]]],
        ];
    }

    #[DataProvider('invalidPreferenceRecords')]
    public function test_new_backups_require_exactly_one_preferences_record(string $variant): void
    {
        $crypto = app(ProtectCredential::class);
        $records = app(WorkspaceBackup::class)->records(false, $crypto);
        if ($variant === 'missing') {
            array_splice($records, 1, 1);
        } else {
            $records[] = $records[1];
        }

        $this->expectException(InvalidArgumentException::class);
        app(WorkspaceRestore::class)->stage($records, $crypto);
    }

    public static function invalidPreferenceRecords(): array
    {
        return [['missing'], ['duplicate']];
    }

    public function test_it_rejects_settings_changes_after_restore_staging(): void
    {
        $project = Project::factory()->create();
        $crypto = app(ProtectCredential::class);
        $restore = app(WorkspaceRestore::class);
        $staged = $restore->stage(app(WorkspaceBackup::class)->records(false, $crypto), $crypto);
        $preferences = app(WorkspacePreferences::class);
        $snapshot = $preferences->merge(['project_defaults' => ['columns' => [['name' => 'New local default', 'color' => null]]]]);

        try {
            $restore->apply($staged);
            $this->fail('Restore accepted stale preferences.');
        } catch (InvalidArgumentException) {
            $this->assertSame($snapshot, $preferences->snapshot());
            $this->assertModelExists($project);
        }
    }

    public function test_failed_restores_roll_back_preferences_and_project_data_together(): void
    {
        $project = Project::factory()->create();
        $preferences = app(WorkspacePreferences::class);
        $preferences->merge(['project_defaults' => ['columns' => [['name' => 'Incoming', 'color' => null]]]]);
        $crypto = app(ProtectCredential::class);
        $records = app(WorkspaceBackup::class)->records(false, $crypto);
        $snapshot = $preferences->merge(['project_defaults' => ['columns' => [['name' => 'Keep local default', 'color' => 'blue']]]]);
        $restore = app(WorkspaceRestore::class);
        $staged = $restore->stage($records, $crypto);
        DB::unprepared("CREATE TRIGGER fail_project_restore BEFORE INSERT ON projects BEGIN SELECT RAISE(ABORT, 'Restore failed'); END");

        try {
            $restore->apply($staged);
            $this->fail('Restore unexpectedly succeeded.');
        } catch (QueryException) {
            $this->assertSame($snapshot, $preferences->snapshot());
            $this->assertModelExists($project);
        } finally {
            DB::unprepared('DROP TRIGGER fail_project_restore');
        }
    }

    public function test_it_replaces_workspace_records_and_restores_private_assets(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('project-icons/source.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl3Gx8AAAAASUVORK5CYII='));
        $project = Project::factory()->create(['icon_type' => 'image', 'icon_path' => 'project-icons/source.png']);
        $column = $project->boardColumns()->first();
        $column->update(['color' => 'purple']);
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
        $this->assertSame('purple', BoardColumn::findOrFail($column->id)->color);
        $this->assertNotSame('project-icons/source.png', $restored->icon_path);
        Storage::disk('local')->assertExists($restored->icon_path);
        $this->assertSame('notes', Storage::disk('local')->get(Task::findOrFail($task->id)->attachment_files[0]['path']));
    }

    public function test_it_restores_older_backups_without_column_colors(): void
    {
        $project = Project::factory()->create();
        $column = $project->boardColumns()->first();
        $column->update(['color' => 'blue']);
        $crypto = $this->mock(ProtectCredential::class, fn ($mock) => $mock->shouldNotReceive('decrypt')->shouldNotReceive('encrypt'));
        $records = app(WorkspaceBackup::class)->records(false, $crypto);
        foreach ($records as &$record) {
            if ($record['type'] === 'board_columns') {
                unset($record['data']['color']);
            }
        }
        unset($record);

        $restore = app(WorkspaceRestore::class);
        $restore->apply($restore->stage($records, $crypto));

        $this->assertNull(BoardColumn::findOrFail($column->id)->color);
    }

    public function test_it_rejects_invalid_column_colors_before_replacing_workspace_records(): void
    {
        $project = Project::factory()->create();
        $column = $project->boardColumns()->first();
        $column->update(['color' => 'green']);
        $crypto = $this->mock(ProtectCredential::class, fn ($mock) => $mock->shouldNotReceive('decrypt')->shouldNotReceive('encrypt'));
        $records = app(WorkspaceBackup::class)->records(false, $crypto);
        foreach ($records as &$record) {
            if ($record['type'] === 'board_columns') {
                $record['data']['color'] = 'rainbow';
                break;
            }
        }
        unset($record);

        try {
            $restore = app(WorkspaceRestore::class);
            $restore->apply($restore->stage($records, $crypto));
            $this->fail('Invalid column color accepted.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseHas('board_columns', ['id' => $column->id, 'color' => 'green']);
            $this->assertModelExists($project);
        }
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
