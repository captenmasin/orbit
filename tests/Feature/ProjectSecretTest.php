<?php

namespace Tests\Feature;

use App\Actions\ProtectCredential;
use App\Models\Project;
use App\Models\ProjectSecret;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Native\Desktop\Dialog;
use Native\Desktop\Facades\Clipboard;
use Native\Desktop\Facades\System;
use RuntimeException;
use Tests\TestCase;

class ProjectSecretTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unlockVault();
    }

    public function test_a_secret_keeps_its_exact_value_out_of_workspace_data_and_blocks_stale_project_removal(): void
    {
        $project = Project::factory()->create();
        $this->mock(ProtectCredential::class, function ($mock): void {
            $mock->shouldReceive('encrypt')->once()->with("  value\nwith spaces  ")->andReturn('native-ciphertext');
        });

        $this->postJson('/projects/'.$project->id.'/secrets', [
            'environment' => '  Default  ',
            'name' => 'PRIVATE_KEY',
            'value' => "  value\nwith spaces  ",
            'project_revision' => 1,
        ])->assertOk()->assertExactJson(['saved' => true]);

        $secret = ProjectSecret::sole();
        $this->assertSame('Default', $secret->environment);
        $this->assertSame('native-ciphertext', $secret->ciphertext);
        $this->assertArrayNotHasKey('ciphertext', $secret->toArray());
        $this->assertDatabaseHas('credential_access_events', ['secret_id' => $secret->id, 'operation' => 'create', 'result' => 'Succeeded']);
        $this->assertSame(2, $project->fresh()->revision);
        $this->deleteJson('/projects/'.$project->id, ['revision' => 1])->assertConflict();
        $this->get('/projects/'.$project->id)->assertInertia(fn (Assert $page): Assert => $page
            ->where('selectedProject.secrets.0.name', 'PRIVATE_KEY')
            ->where('selectedProject.secrets.0.environment', 'Default')
            ->missing('selectedProject.secrets.0.ciphertext'));
    }

    public function test_empty_values_are_preserved_and_validation_never_flashes_secret_values(): void
    {
        $project = Project::factory()->create();
        $this->mock(ProtectCredential::class, function ($mock): void {
            $mock->shouldReceive('encrypt')->once()->with('')->andReturn('empty-ciphertext');
        });

        $this->postJson('/projects/'.$project->id.'/secrets', [
            'environment' => 'Default',
            'name' => 'EMPTY_VALUE',
            'value' => '',
            'project_revision' => 1,
        ])->assertOk();
        $this->assertSame('empty-ciphertext', ProjectSecret::sole()->ciphertext);

        $this->from('/projects/'.$project->id)->post('/projects/'.$project->id.'/secrets', [
            'environment' => 'Default',
            'name' => 'not a valid name',
            'value' => 'VALUE-THAT-MUST-NOT-FLASH',
            'project_revision' => 2,
        ])->assertRedirect('/projects/'.$project->id)->assertSessionHasErrors('name')->assertSessionMissing('_old_input.value');
    }

    public function test_env_paste_creates_every_literal_entry_or_saves_nothing(): void
    {
        $project = Project::factory()->create();
        $this->mock(ProtectCredential::class, function ($mock): void {
            $mock->shouldReceive('encrypt')->once()->with('en')->andReturn('locale-ciphertext');
            $mock->shouldReceive('encrypt')->once()->with('en_US')->andReturn('faker-ciphertext');
        });

        $url = '/projects/'.$project->id.'/secrets/paste';
        $this->postJson($url, [
            'environment' => 'Default',
            'service' => 'Application',
            'entries' => "APP_LOCALE=en\r\nAPP_FAKER_LOCALE=en_US\r\n",
            'project_revision' => 1,
        ])->assertOk()->assertExactJson(['saved' => true]);
        $this->assertSame(['APP_FAKER_LOCALE', 'APP_LOCALE'], $project->secrets()->pluck('name')->all());
        $this->assertSame(['Application', 'Application'], $project->secrets()->pluck('service')->all());
        $this->assertSame(2, $project->fresh()->revision);
        $this->assertDatabaseCount('credential_access_events', 2);

        $this->from('/projects/'.$project->id)->post($url, [
            'environment' => 'Default',
            'entries' => "APP_TIMEZONE=UTC\nnot valid",
            'project_revision' => 2,
        ])->assertRedirect('/projects/'.$project->id)->assertSessionHasErrors('entries')->assertSessionMissing('_old_input.entries');
        $this->assertDatabaseCount('project_secrets', 2);
        $this->assertSame(2, $project->fresh()->revision);
    }

    public function test_native_env_import_previews_names_skips_collisions_and_rechecks_the_file(): void
    {
        config(['nativephp-internal.running' => true]);
        $project = Project::factory()->create();
        ProjectSecret::factory()->for($project)->create(['environment' => 'Default', 'name' => 'APP_LOCALE']);
        $path = tempnam(sys_get_temp_dir(), 'orbit-env-').'.env';
        File::put($path, "APP_LOCALE=en\nAPP_FAKER_LOCALE=en_US\n");
        $this->mock(Dialog::class, function ($mock) use ($path): void {
            $mock->shouldReceive('files->withHiddenFiles->title->button->asSheet->open')->once()->andReturn($path);
        });
        try {
            $preview = '/projects/'.$project->id.'/secrets/import/preview';
            $this->postJson($preview, ['environment' => 'Default'])->assertOk()
                ->assertJsonPath('preview.source', basename($path))
                ->assertJsonPath('preview.entries.0.name', 'APP_LOCALE')
                ->assertJsonPath('preview.entries.0.collision', true)
                ->assertJsonPath('preview.entries.1.name', 'APP_FAKER_LOCALE')
                ->assertJsonPath('preview.entries.1.collision', false)
                ->assertJsonMissing(['en_US']);
            $this->mock(ProtectCredential::class, function ($mock): void {
                $mock->shouldReceive('encrypt')->once()->with('en_US')->andReturn('faker-ciphertext');
            });
            $this->postJson('/projects/'.$project->id.'/secrets/import', ['environment' => 'Default', 'project_revision' => 1])
                ->assertOk()->assertExactJson(['saved' => true, 'imported' => 1, 'skipped' => 1]);
            $this->assertDatabaseHas('project_secrets', ['project_id' => $project->id, 'name' => 'APP_FAKER_LOCALE', 'ciphertext' => 'faker-ciphertext']);
            $this->assertDatabaseHas('credential_access_events', ['operation' => 'import', 'result' => 'Succeeded']);
            $this->assertSame(2, $project->fresh()->revision);
        } finally {
            File::delete($path);
        }
    }

    public function test_env_import_rejects_a_file_changed_after_preview_without_writing(): void
    {
        config(['nativephp-internal.running' => true]);
        $project = Project::factory()->create();
        $path = tempnam(sys_get_temp_dir(), 'orbit-env-').'.env';
        File::put($path, 'APP_LOCALE=en');
        $this->mock(Dialog::class, function ($mock) use ($path): void {
            $mock->shouldReceive('files->withHiddenFiles->title->button->asSheet->open')->once()->andReturn($path);
        });
        try {
            $this->postJson('/projects/'.$project->id.'/secrets/import/preview', ['environment' => 'Default'])->assertOk();
            File::put($path, 'APP_LOCALE=fr');
            $this->postJson('/projects/'.$project->id.'/secrets/import', ['environment' => 'Default', 'project_revision' => 1])
                ->assertUnprocessable()->assertJsonValidationErrors('entries');
            $this->assertDatabaseCount('project_secrets', 0);
            $this->assertSame(1, $project->fresh()->revision);
        } finally {
            File::delete($path);
        }
    }

    public function test_env_import_preserves_empty_values_returned_by_native_encryption(): void
    {
        config(['nativephp-internal.running' => true]);
        $project = Project::factory()->create();
        $path = tempnam(sys_get_temp_dir(), 'orbit-env-');
        File::put($path, "APP_NAME=Orbit\nDB_PASSWORD=\n");
        $this->mock(Dialog::class, function ($mock) use ($path): void {
            $mock->shouldReceive('files->withHiddenFiles->title->button->asSheet->open')->once()->andReturn($path);
        });
        System::shouldReceive('canEncrypt')->times(3)->andReturn(true);
        System::shouldReceive('encrypt')->once()->with('Orbit')->andReturn('native-ciphertext');
        System::shouldReceive('encrypt')->once()->with('')->andReturn('');
        System::shouldReceive('decrypt')->once()->with('')->andReturn('');

        try {
            $this->postJson('/projects/'.$project->id.'/secrets/import/preview', ['environment' => 'Local'])->assertOk();
            $this->postJson('/projects/'.$project->id.'/secrets/import', ['environment' => 'Local', 'project_revision' => 1])
                ->assertOk()->assertExactJson(['saved' => true, 'imported' => 2, 'skipped' => 0]);
            $this->get('/projects/'.$project->id)->assertInertia(fn (Assert $page): Assert => $page
                ->has('selectedProject.secrets', 2)->where('selectedProject.secrets.1.name', 'DB_PASSWORD')
                ->where('selectedProject.secrets.1.environment', 'Local')->missing('selectedProject.secrets.1.ciphertext'));
            $secret = $project->secrets()->where('name', 'DB_PASSWORD')->sole();
            $this->unlockVault();
            $this->getJson('/projects/'.$project->id.'/secrets/'.$secret->id.'/value?revision=1')->assertExactJson(['value' => '']);
            $this->assertSame(2, $project->fresh()->revision);
        } finally {
            File::delete($path);
        }
    }

    public function test_native_env_export_writes_only_selected_secrets_after_destination_preview(): void
    {
        config(['nativephp-internal.running' => true]);
        $project = Project::factory()->create();
        $first = ProjectSecret::factory()->for($project)->create(['environment' => 'Default', 'name' => 'APP_LOCALE', 'ciphertext' => 'locale-ciphertext']);
        $second = ProjectSecret::factory()->for($project)->create(['environment' => 'Default', 'name' => 'APP_FAKER_LOCALE', 'ciphertext' => 'faker-ciphertext']);
        $path = tempnam(sys_get_temp_dir(), 'orbit-env-').'.env';
        File::delete($path);
        $this->mock(Dialog::class, function ($mock) use ($path): void {
            $mock->shouldReceive('title->filter->button->asSheet->save')->once()->andReturn($path);
        });
        $selection = ['environment' => 'Default', 'names' => ['APP_LOCALE', 'APP_FAKER_LOCALE']];
        try {
            $this->postJson('/projects/'.$project->id.'/secrets/export/preview', $selection)->assertOk()
                ->assertJsonPath('preview.destination', basename($path))->assertJsonPath('preview.exists', false);
            $this->mock(ProtectCredential::class, function ($mock): void {
                $mock->shouldReceive('decrypt')->once()->with('locale-ciphertext')->andReturn('en');
                $mock->shouldReceive('decrypt')->once()->with('faker-ciphertext')->andReturn('en_US');
            });
            $this->unlockVault();
            $this->postJson('/projects/'.$project->id.'/secrets/export', [...$selection, 'project_revision' => 1, 'overwrite' => false])
                ->assertOk()->assertExactJson(['exported' => true]);
            $this->assertSame("APP_LOCALE=\"en\"\nAPP_FAKER_LOCALE=\"en_US\"\n", File::get($path));
            $this->assertDatabaseHas('credential_access_events', ['secret_id' => $first->id, 'operation' => 'export', 'result' => 'Succeeded']);
            $this->assertDatabaseHas('credential_access_events', ['secret_id' => $second->id, 'operation' => 'export', 'result' => 'Succeeded']);
        } finally {
            File::delete($path);
        }
    }

    public function test_env_export_refuses_a_destination_that_changed_after_preview(): void
    {
        config(['nativephp-internal.running' => true]);
        $project = Project::factory()->create();
        ProjectSecret::factory()->for($project)->create(['environment' => 'Default', 'name' => 'APP_LOCALE']);
        $path = tempnam(sys_get_temp_dir(), 'orbit-env-').'.env';
        File::delete($path);
        $this->mock(Dialog::class, function ($mock) use ($path): void {
            $mock->shouldReceive('title->filter->button->asSheet->save')->once()->andReturn($path);
        });
        $selection = ['environment' => 'Default', 'names' => ['APP_LOCALE']];
        try {
            $this->postJson('/projects/'.$project->id.'/secrets/export/preview', $selection)->assertOk();
            File::put($path, 'intervening contents');
            $this->unlockVault();
            $this->postJson('/projects/'.$project->id.'/secrets/export', [...$selection, 'project_revision' => 1, 'overwrite' => false])
                ->assertUnprocessable()->assertJsonValidationErrors('names');
            $this->assertSame('intervening contents', File::get($path));
        } finally {
            File::delete($path);
        }
    }

    public function test_secret_replacement_and_removal_use_independent_revisions(): void
    {
        $project = Project::factory()->create();
        $secret = ProjectSecret::factory()->for($project)->create();
        $this->mock(ProtectCredential::class, function ($mock): void {
            $mock->shouldReceive('encrypt')->once()->with("replacement\nvalue")->andReturn('replacement-ciphertext');
        });

        $url = '/projects/'.$project->id.'/secrets/'.$secret->id;
        $this->putJson($url, ['value' => "replacement\nvalue", 'project_revision' => 1, 'revision' => 1])->assertOk()->assertExactJson(['saved' => true]);
        $this->assertSame('replacement-ciphertext', $secret->fresh()->ciphertext);
        $this->assertSame(2, $secret->fresh()->revision);
        $this->assertSame(2, $project->fresh()->revision);
        $this->putJson($url, ['value' => 'stale', 'project_revision' => 2, 'revision' => 1])->assertConflict();
        $this->assertSame('replacement-ciphertext', $secret->fresh()->ciphertext);
        $this->deleteJson($url, ['project_revision' => 2, 'revision' => 2])->assertOk()->assertExactJson(['removed' => true]);
        $this->assertModelMissing($secret);
        $this->assertSame(3, $project->fresh()->revision);
        $this->assertDatabaseHas('credential_access_events', ['secret_id' => $secret->id, 'operation' => 'remove', 'result' => 'Succeeded']);
    }

    public function test_reveal_is_scoped_no_store_and_records_safe_failures(): void
    {
        $project = Project::factory()->create();
        $secret = ProjectSecret::factory()->for($project)->create(['ciphertext' => 'native-ciphertext']);
        $this->mock(ProtectCredential::class, function ($mock): void {
            $mock->shouldReceive('decrypt')->once()->with('native-ciphertext')->andReturn("multiline\nvalue");
        });

        $url = '/projects/'.$project->id.'/secrets/'.$secret->id.'/value?revision=1';
        $this->unlockVault();
        $this->getJson($url)->assertOk()->assertExactJson(['value' => "multiline\nvalue"])->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseHas('credential_access_events', ['secret_id' => $secret->id, 'operation' => 'reveal', 'result' => 'Succeeded']);
        $other = Project::factory()->create();
        $this->getJson('/projects/'.$other->id.'/secrets/'.$secret->id.'/value?revision=1')->assertNotFound();

        $this->mock(ProtectCredential::class, function ($mock): void {
            $mock->shouldReceive('decrypt')->once()->andThrow(new RuntimeException('Native credential storage is unavailable. Open the desktop app and try again.'));
        });
        $this->getJson($url)->assertUnprocessable()->assertJsonMissing(['native-ciphertext']);
        $this->assertDatabaseHas('credential_access_events', ['secret_id' => $secret->id, 'operation' => 'reveal', 'result' => 'Failed']);
    }

    public function test_copy_uses_the_native_clipboard_without_returning_the_value(): void
    {
        $project = Project::factory()->create();
        $secret = ProjectSecret::factory()->for($project)->create(['ciphertext' => 'native-ciphertext']);
        $this->mock(ProtectCredential::class, function ($mock): void {
            $mock->shouldReceive('decrypt')->twice()->with('native-ciphertext')->andReturn('clipboard-value');
        });
        Clipboard::shouldReceive('text')->once()->with('clipboard-value')->andReturn('clipboard-value');
        Clipboard::shouldReceive('text')->twice()->withNoArgs()->andReturn('clipboard-value', 'different-value');
        Clipboard::shouldReceive('clear')->never();

        $url = '/projects/'.$project->id.'/secrets/'.$secret->id.'/copy';
        $this->unlockVault();
        $this->postJson($url, ['revision' => 1])->assertOk()->assertExactJson(['copied' => true]);
        $this->deleteJson($url, ['revision' => 1])->assertOk()->assertExactJson(['cleared' => true]);
        $this->assertDatabaseHas('credential_access_events', ['secret_id' => $secret->id, 'operation' => 'copy', 'result' => 'Succeeded']);
    }

    public function test_duplicate_names_and_encryption_failures_preserve_existing_secrets(): void
    {
        $project = Project::factory()->create();
        $secret = ProjectSecret::factory()->for($project)->create(['environment' => 'Default', 'name' => 'APP_KEY']);
        $this->postJson('/projects/'.$project->id.'/secrets', [
            'environment' => 'Default',
            'name' => 'APP_KEY',
            'value' => 'not-used',
            'project_revision' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame(1, $project->fresh()->revision);

        $this->mock(ProtectCredential::class, function ($mock): void {
            $mock->shouldReceive('encrypt')->once()->andThrow(new RuntimeException('Native credential storage is unavailable. Open the desktop app and try again.'));
        });
        $this->putJson('/projects/'.$project->id.'/secrets/'.$secret->id, ['value' => 'replacement', 'project_revision' => 1, 'revision' => 1])
            ->assertUnprocessable()->assertJsonPath('message', 'Native credential storage is unavailable. Open the desktop app and try again.');

        $this->assertSame('fixture-ciphertext', $secret->fresh()->ciphertext);
        $this->assertSame(1, $secret->fresh()->revision);
        $this->assertSame(1, $project->fresh()->revision);
    }

    private function unlockVault(): void
    {
        $this->withSession(['secret_pin_unlocked_until' => now()->addMinutes(5)->timestamp]);
    }
}
