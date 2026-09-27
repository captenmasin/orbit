<?php

namespace Tests\Feature;

use App\Actions\ProtectCredential;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\ProjectSecret;
use App\OrbitBackup;
use App\WorkspaceBackup;
use App\WorkspacePreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Native\Desktop\Dialog;
use Native\Desktop\Facades\Settings;
use Tests\TestCase;

class BackupExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_exports_an_encrypted_workspace_backup(): void
    {
        $this->freezeTime();
        config(['nativephp-internal.running' => true]);
        $path = tempnam(sys_get_temp_dir(), 'orbit-backup-test-');
        unlink($path);
        $this->mock(Dialog::class, fn ($mock) => $mock->shouldReceive('title->filter->button->asSheet->save')->once()->andReturn($path));

        try {
            $this->postJson('/backups/export/preview')->assertOk()->assertJsonPath('preview.destination', basename($path));
            $this->postJson('/backups/export', [
                'password' => 'correct horse battery staple',
                'password_confirmation' => 'correct horse battery staple',
                'include_secrets' => false,
                'overwrite' => false,
            ])->assertOk()->assertJson(['exported' => true]);

            $records = app(OrbitBackup::class)->read(file_get_contents($path), 'correct horse battery staple');
            $this->assertSame('workspace', $records[0]['type']);
            $this->assertFalse($records[0]['data']['includes_secrets']);
            $this->assertSame(now()->toIso8601String(), app(WorkspacePreferences::class)->get('backups.last_export_at'));
            $this->assertSame($path, app(WorkspacePreferences::class)->get('backups.last_export_path'));
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    public function test_preferred_backup_folders_seed_the_save_dialog(): void
    {
        $this->freezeTime();
        config(['nativephp-internal.running' => true]);
        app(WorkspacePreferences::class)->merge(['backups' => ['folder' => sys_get_temp_dir()]]);
        $this->mock(Dialog::class, function ($mock): void {
            $mock->shouldReceive('defaultPath')->once()->with(sys_get_temp_dir().'/Orbit-'.now()->format('Y-m-d').'.orbitbackup')->andReturnSelf();
            $mock->shouldReceive('title->filter->button->asSheet->save')->once()->andReturn(null);
        });

        $this->postJson('/backups/export/preview')->assertOk()->assertJsonPath('preview', null);
    }

    public function test_backup_metadata_exposes_no_credentials_and_folder_selection_is_unsaved(): void
    {
        config(['nativephp-internal.running' => true]);
        $snapshot = app(WorkspacePreferences::class)->merge(['ai' => ['credential' => 'private-encrypted-key']]);
        $this->mock(Dialog::class, fn ($mock) => $mock->shouldReceive('folders->title->button->asSheet->open')->once()->andReturn(sys_get_temp_dir()));

        $this->getJson('/backups/preferences')->assertOk()->assertExactJson([
            'revision' => $snapshot['revision'],
            'backups' => ['folder' => null, 'last_export_at' => null, 'last_export_path' => null],
        ]);
        $this->postJson('/backups/folder')->assertOk()->assertJsonPath('folder', sys_get_temp_dir());

        $this->assertSame($snapshot, app(WorkspacePreferences::class)->snapshot());
    }

    public function test_folder_selection_requires_the_native_app(): void
    {
        $this->postJson('/backups/folder')->assertUnprocessable()->assertJsonValidationErrors('folder');

        $this->assertNull(app(WorkspacePreferences::class)->get('backups.folder'));
    }

    public function test_unavailable_backup_folders_allow_selecting_another_destination(): void
    {
        config(['nativephp-internal.running' => true]);
        app(WorkspacePreferences::class)->merge(['backups' => ['folder' => '/unavailable-orbit-backup-folder']]);
        $path = sys_get_temp_dir().'/'.Str::uuid7().'.orbitbackup';
        $this->mock(Dialog::class, fn ($mock) => $mock->shouldNotReceive('defaultPath')->shouldReceive('title->filter->button->asSheet->save')->once()->andReturn($path));

        $this->postJson('/backups/export/preview')->assertOk()->assertJsonPath('preview.destination', basename($path))->assertJsonPath('folder_unavailable', true);

        $this->assertSame('/unavailable-orbit-backup-folder', app(WorkspacePreferences::class)->get('backups.folder'));
    }

    public function test_failed_exports_preserve_the_last_successful_export_record(): void
    {
        config(['nativephp-internal.running' => true]);
        $preferences = app(WorkspacePreferences::class);
        $snapshot = $preferences->merge(['backups' => ['last_export_at' => '2026-09-26T12:00:00+00:00', 'last_export_path' => '/previous.orbitbackup']]);
        $path = sys_get_temp_dir().'/'.Str::uuid7().'/missing.orbitbackup';
        $this->mock(Dialog::class, fn ($mock) => $mock->shouldReceive('title->filter->button->asSheet->save')->once()->andReturn($path));
        $this->postJson('/backups/export/preview')->assertOk();

        $this->postJson('/backups/export', [
            'password' => 'correct horse battery staple',
            'password_confirmation' => 'correct horse battery staple',
            'include_secrets' => false,
            'overwrite' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors('backup');

        $this->assertSame($snapshot, $preferences->snapshot());
    }

    public function test_it_never_flashes_backup_passwords(): void
    {
        $this->from('/settings/backups')->post('/backups/export', [
            'password' => 'correct horse battery staple',
            'password_confirmation' => 'different password value',
        ])->assertRedirect('/settings/backups')->assertSessionHasErrors('password_confirmation')->assertSessionMissing('_old_input.password');
    }

    public function test_exporting_secret_values_requires_the_pin_without_losing_the_destination(): void
    {
        config(['nativephp-internal.running' => true]);
        $pinHash = null;
        Settings::shouldReceive('get')->with('secrets.pin_hash')->andReturnUsing(function () use (&$pinHash): ?string {
            return $pinHash;
        });
        Settings::shouldReceive('set')->with('secrets.pin_hash', \Mockery::type('string'))
            ->andReturnUsing(function (string $key, string $hash) use (&$pinHash): void {
                $pinHash = $hash;
            });
        $path = tempnam(sys_get_temp_dir(), 'orbit-backup-test-');
        unlink($path);
        $this->mock(Dialog::class, fn ($mock) => $mock->shouldReceive('title->filter->button->asSheet->save')->once()->andReturn($path));
        $project = Project::factory()->create();
        ProjectSecret::factory()->for($project)->create(['ciphertext' => 'encrypted-value']);
        $data = [
            'password' => 'correct horse battery staple',
            'password_confirmation' => 'correct horse battery staple',
            'include_secrets' => true,
            'overwrite' => false,
        ];

        try {
            $this->postJson('/backups/export/preview')->assertOk();
            $this->postJson('/backups/export', $data)->assertStatus(423)->assertSessionHas('backup-export.path');
            $this->postJson('/secrets/pin', ['pin' => '1234', 'pin_confirmation' => '1234'])->assertOk();
            $this->mock(ProtectCredential::class, function ($mock): void {
                $mock->shouldReceive('decrypt')->once()->with('encrypted-value')->andReturn('plain-value');
            });
            $this->postJson('/backups/export', $data)->assertOk();
            $records = app(OrbitBackup::class)->read(file_get_contents($path), $data['password']);
            $this->assertSame('plain-value', collect($records)->firstWhere('type', 'project_secrets')['data']['value']);
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    public function test_it_previews_a_valid_backup_without_disclosing_secret_values(): void
    {
        config(['nativephp-internal.running' => true]);
        $path = tempnam(sys_get_temp_dir(), 'orbit-backup-test-');
        file_put_contents($path, app(OrbitBackup::class)->write(app(WorkspaceBackup::class)->records(false, app(ProtectCredential::class)), 'correct horse battery staple'));
        $this->mock(Dialog::class, fn ($mock) => $mock->shouldReceive('files->filter->title->button->asSheet->open')->once()->andReturn($path));

        try {
            $this->postJson('/backups/restore/preview', ['password' => 'correct horse battery staple'])
                ->assertOk()->assertJsonPath('preview.includes_secrets', false)->assertJsonMissing(['value'])->assertSessionHas('backup-restore.hash');
        } finally {
            unlink($path);
        }
    }

    public function test_preference_changes_after_preview_reject_restore(): void
    {
        config(['nativephp-internal.running' => true]);
        $project = Project::factory()->create();
        $path = tempnam(sys_get_temp_dir(), 'orbit-backup-test-');
        file_put_contents($path, app(OrbitBackup::class)->write(app(WorkspaceBackup::class)->records(false, app(ProtectCredential::class)), 'correct horse battery staple'));
        $this->mock(Dialog::class, fn ($mock) => $mock->shouldReceive('files->filter->title->button->asSheet->open')->once()->andReturn($path));

        try {
            $this->postJson('/backups/restore/preview', ['password' => 'correct horse battery staple'])->assertOk();
            $snapshot = app(WorkspacePreferences::class)->merge(['project_defaults' => ['columns' => [['name' => 'Changed after preview', 'color' => null]]]]);

            $this->postJson('/backups/restore', ['password' => 'correct horse battery staple', 'confirm' => true])
                ->assertUnprocessable()->assertJsonValidationErrors('backup');

            $this->assertSame($snapshot, app(WorkspacePreferences::class)->snapshot());
            $this->assertModelExists($project);
        } finally {
            unlink($path);
        }
    }

    public function test_restore_snapshots_the_active_native_database_and_applies_the_backup(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'orbit-native-restore-');
        $backupPath = tempnam(sys_get_temp_dir(), 'orbit-native-backup-');
        config([
            'nativephp-internal.running' => true,
            'database.connections.native_restore' => [...config('database.connections.sqlite'), 'database' => $database],
        ]);
        DB::setDefaultConnection('native_restore');
        $this->mock(Dialog::class, fn ($mock) => $mock->shouldReceive('files->filter->title->button->asSheet->open')->once()->andReturn($backupPath));
        $crypto = $this->mock(ProtectCredential::class, function ($mock): void {
            $mock->shouldReceive('decrypt')->once()->with('original-ciphertext')->andReturn('dummy-backup-value');
            $mock->shouldReceive('encrypt')->twice()->with('dummy-backup-value')->andReturn('restored-ciphertext');
        });

        try {
            $this->artisan('migrate', ['--database' => 'native_restore', '--force' => true, '--no-interaction' => true])->assertExitCode(0);
            DB::statement('PRAGMA journal_mode=WAL');
            $project = Project::factory()->create(['name' => 'Backed up project']);
            $document = ProjectDocument::factory()->for($project)->create(['body' => 'Backed up document']);
            $secret = ProjectSecret::factory()->for($project)->create(['ciphertext' => 'original-ciphertext']);
            app(WorkspacePreferences::class)->merge(['project_defaults' => ['columns' => [['name' => 'Incoming default', 'color' => 'blue']]]]);
            file_put_contents($backupPath, app(OrbitBackup::class)->write(app(WorkspaceBackup::class)->records(true, $crypto), 'correct horse battery staple'));
            app(WorkspacePreferences::class)->merge(['project_defaults' => ['columns' => [['name' => 'Keep in rollback snapshot', 'color' => null]]], 'backups' => ['folder' => '/local/backups']]);
            $document->update(['body' => 'Keep this in the rollback snapshot']);
            Project::factory()->create(['name' => 'Present before restore']);

            $this->postJson('/backups/restore/preview', ['password' => 'correct horse battery staple'])
                ->assertOk()->assertJsonPath('preview.projects', 1)->assertJsonPath('preview.secrets', 1);
            $this->postJson('/backups/restore', ['password' => 'correct horse battery staple', 'confirm' => true])
                ->assertOk()->assertJson(['restored' => true]);

            $this->assertSame(1, Project::count());
            $this->assertSame('Backed up document', $document->fresh()->body);
            $this->assertSame('restored-ciphertext', $secret->fresh()->ciphertext);
            $this->assertSame([['name' => 'Incoming default', 'color' => 'blue']], app(WorkspacePreferences::class)->get('project_defaults.columns'));
            $this->assertSame('/local/backups', app(WorkspacePreferences::class)->get('backups.folder'));
            $state = DB::table('restore_states')->sole();
            $this->assertSame('Applied', $state->phase);
            $this->assertSame(0600, fileperms($state->snapshot_path) & 0777);
            $snapshot = new \SQLite3($state->snapshot_path, SQLITE3_OPEN_READONLY);
            $this->assertSame(2, $snapshot->querySingle('SELECT COUNT(*) FROM projects'));
            $this->assertSame('Keep this in the rollback snapshot', $snapshot->querySingle('SELECT body FROM project_documents'));
            $snapshotPreferences = json_decode($snapshot->querySingle('SELECT "values" FROM workspace_preferences WHERE id = 1'), true);
            $this->assertSame([['name' => 'Keep in rollback snapshot', 'color' => null]], $snapshotPreferences['project_defaults']['columns']);
            $this->assertSame('ok', $snapshot->querySingle('PRAGMA integrity_check'));
            $snapshot->close();
        } finally {
            DB::setDefaultConnection('sqlite');
            DB::purge('native_restore');
            foreach ([$database, $database.'-wal', $database.'-shm', $backupPath] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
