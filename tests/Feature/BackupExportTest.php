<?php

namespace Tests\Feature;

use App\Actions\ProtectCredential;
use App\OrbitBackup;
use App\WorkspaceBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Native\Desktop\Dialog;
use Tests\TestCase;

class BackupExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_exports_an_encrypted_workspace_backup(): void
    {
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
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    public function test_it_never_flashes_backup_passwords(): void
    {
        $this->from('/settings/backups')->post('/backups/export', [
            'password' => 'correct horse battery staple',
            'password_confirmation' => 'different password value',
        ])->assertRedirect('/settings/backups')->assertSessionHasErrors('password_confirmation')->assertSessionMissing('_old_input.password');
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
}
