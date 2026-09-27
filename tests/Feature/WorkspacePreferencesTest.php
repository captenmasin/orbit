<?php

namespace Tests\Feature;

use App\Mcp\Servers\OrbitServer;
use App\Mcp\Tools\ManageProjectTool;
use App\Models\Project;
use App\SecretVault;
use App\WorkspacePreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Native\Desktop\Enums\SystemThemesEnum;
use Native\Desktop\Facades\App;
use Native\Desktop\Facades\System;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class WorkspacePreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_section_saves_persist_and_stale_saves_cannot_overwrite_other_sections(): void
    {
        $this->assertSame('system', app(WorkspacePreferences::class)->get('appearance.theme'));
        $this->assertSame('system', app(WorkspacePreferences::class)->get('appearance.reduce_motion'));
        $this->assertSame(15, app(WorkspacePreferences::class)->get('security.lock_minutes'));
        $this->putJson('/settings/appearance', ['revision' => 1, 'theme' => 'dark'])
            ->assertOk()->assertJsonPath('preferences.revision', 2);

        $this->putJson('/settings/general', ['revision' => 1, 'startup_destination' => 'last_project'])->assertConflict();

        $this->assertSame('dashboard', (new WorkspacePreferences)->get('general.startup_destination'));
        $this->assertSame('dark', (new WorkspacePreferences)->get('appearance.theme'));
        $this->putJson('/settings/general', ['revision' => 2, 'startup_destination' => 'last_project'])
            ->assertOk()->assertJsonPath('preferences.revision', 3);
        $this->assertSame('last_project', (new WorkspacePreferences)->get('general.startup_destination'));
        $this->assertSame('dark', (new WorkspacePreferences)->get('appearance.theme'));
    }

    public function test_older_saved_appearance_preferences_use_the_system_motion_default(): void
    {
        DB::table('workspace_preferences')->insert(['id' => 1, 'revision' => 7, 'values' => json_encode(['appearance' => ['theme' => 'dark']], JSON_THROW_ON_ERROR)]);

        $this->get('/settings')->assertInertia(fn (Assert $page): Assert => $page
            ->where('appearance', 'dark')->where('reduceMotion', 'system')
            ->where('preferences.values.appearance.reduce_motion', 'system'));

        $this->assertSame('system', app(WorkspacePreferences::class)->get('appearance.reduce_motion'));
        $this->assertSame(7, app(WorkspacePreferences::class)->snapshot()['revision']);
    }

    #[TestWith(['system'])]
    #[TestWith(['on'])]
    #[TestWith(['off'])]
    public function test_motion_preferences_persist_and_are_shared_with_pages(string $reduceMotion): void
    {
        $this->putJson('/settings/appearance', ['revision' => 1, 'theme' => 'dark', 'reduce_motion' => $reduceMotion])
            ->assertOk()->assertJsonPath('preferences.values.appearance.reduce_motion', $reduceMotion);

        $this->assertSame($reduceMotion, (new WorkspacePreferences)->get('appearance.reduce_motion'));
        $this->get('/settings')->assertInertia(fn (Assert $page): Assert => $page
            ->where('appearance', 'dark')->where('reduceMotion', $reduceMotion));
    }

    public function test_theme_only_saves_preserve_the_saved_motion_preference(): void
    {
        app(WorkspacePreferences::class)->merge(['appearance' => ['reduce_motion' => 'on']]);

        $this->putJson('/settings/appearance', ['revision' => 2, 'theme' => 'light'])
            ->assertOk()->assertJsonPath('preferences.values.appearance.theme', 'light')
            ->assertJsonPath('preferences.values.appearance.reduce_motion', 'on');

        $this->assertSame(['theme' => 'light', 'reduce_motion' => 'on'], (new WorkspacePreferences)->get('appearance'));
    }

    #[DataProvider('invalidSections')]
    public function test_invalid_section_values_leave_saved_preferences_intact(string $section, array $values, string $field): void
    {
        $preferences = app(WorkspacePreferences::class);
        $snapshot = $preferences->snapshot();

        $this->putJson('/settings/'.$section, ['revision' => 1, ...$values])->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertSame($snapshot, $preferences->snapshot());
    }

    public static function invalidSections(): array
    {
        return [
            'startup URL' => ['general', ['startup_destination' => '/projects/unsafe'], 'startup_destination'],
            'unknown theme' => ['appearance', ['theme' => 'auto'], 'theme'],
            'unknown motion preference' => ['appearance', ['theme' => 'dark', 'reduce_motion' => 'auto'], 'reduce_motion'],
            'non-string motion preference' => ['appearance', ['theme' => 'dark', 'reduce_motion' => true], 'reduce_motion'],
            'invalid lock duration' => ['security', ['lock_minutes' => 7, 'clipboard_seconds' => 30], 'lock_minutes'],
            'invalid clipboard duration' => ['security', ['lock_minutes' => 15, 'clipboard_seconds' => 20], 'clipboard_seconds'],
            'empty columns' => ['project_defaults', ['columns' => []], 'columns'],
            'invalid folder' => ['backups', ['folder' => '/unavailable-orbit-backup-folder'], 'folder'],
        ];
    }

    public function test_settings_never_return_private_preferences_or_allow_clients_to_replace_them(): void
    {
        app(WorkspacePreferences::class)->merge([
            'ai' => ['credential' => 'private-encrypted-key'],
            'startup' => ['last_project_id' => 'private-startup-state'],
            'security' => ['generation' => 77],
        ]);

        $this->get('/settings')->assertInertia(fn (Assert $page): Assert => $page->component('Settings')
            ->missing('preferences.values.ai')->missing('preferences.values.startup')->missing('preferences.values.security.generation'));
        $this->putJson('/settings/security', ['revision' => 2, 'lock_minutes' => 15, 'clipboard_seconds' => 60, 'generation' => 1])
            ->assertOk()->assertJsonMissingPath('preferences.values.security.generation');

        $this->assertSame(77, app(WorkspacePreferences::class)->get('security.generation'));
        $this->assertSame('private-encrypted-key', app(WorkspacePreferences::class)->get('ai.credential'));
    }

    public function test_changing_security_duration_revokes_unlocks_and_rejects_a_stale_generation_save(): void
    {
        $this->freezeTime();
        $hash = Hash::make('1234');
        DB::table('secret_vaults')->insert(['id' => 1, 'pin_hash' => $hash]);
        $this->withSession([
            'secret_pin_unlocked_until' => now()->addMinutes(15)->timestamp,
            'secret_pin_version' => hash('sha256', $hash),
            'secret_pin_generation' => 0,
            'secret_pin_lock_minutes' => 15,
        ])->getJson('/secrets/unlock/status')->assertJsonPath('unlocked', true);

        $this->putJson('/settings/security', ['revision' => 1, 'lock_minutes' => 60, 'clipboard_seconds' => 30])
            ->assertOk()->assertJsonPath('preferences.values.security.lock_minutes', 60);

        $preferences = app(WorkspacePreferences::class);
        $snapshot = $preferences->snapshot();
        $this->assertNotSame(0, $preferences->get('security.generation'));
        $this->getJson('/secrets/unlock/status')->assertJsonPath('unlocked', false)->assertSessionMissing('secret_pin_version');
        app(SecretVault::class)->revokeUnlocks();
        $this->putJson('/settings/security', ['revision' => $snapshot['revision'], 'lock_minutes' => 5, 'clipboard_seconds' => 0])->assertConflict();
        $this->assertSame(60, $preferences->get('security.lock_minutes'));
        $this->assertSame(30, $preferences->get('security.clipboard_seconds'));
    }

    public function test_native_appearance_failure_rolls_back_the_saved_preference(): void
    {
        config(['nativephp-internal.running' => true]);
        System::shouldReceive('theme')->once()->with(SystemThemesEnum::from('dark'))->andThrow(new RuntimeException('Native bridge failed'));
        $snapshot = app(WorkspacePreferences::class)->snapshot();

        $this->putJson('/settings/appearance', ['revision' => 1, 'theme' => 'dark'])->assertUnprocessable()->assertJsonValidationErrors('theme');

        $this->assertSame($snapshot, app(WorkspacePreferences::class)->snapshot());
    }

    public function test_native_appearance_previews_do_not_persist_until_saved(): void
    {
        config(['nativephp-internal.running' => true]);
        System::shouldReceive('theme')->twice()->with(SystemThemesEnum::LIGHT)->andReturn(SystemThemesEnum::LIGHT);

        $this->postJson('/settings/appearance/preview', ['theme' => 'light'])->assertOk()->assertJsonPath('previewed', true);

        $this->assertSame('system', app(WorkspacePreferences::class)->get('appearance.theme'));
        $this->putJson('/settings/appearance', ['revision' => 1, 'theme' => 'light'])->assertOk();
        $this->assertSame('light', app(WorkspacePreferences::class)->get('appearance.theme'));
    }

    public function test_saved_columns_apply_to_ui_and_mcp_creation_but_keep_existing_and_duplicated_boards(): void
    {
        $existing = Project::factory()->create();
        $existingNames = $existing->boardColumns->pluck('name')->all();
        $columns = [['name' => '  Ready  ', 'color' => 'blue'], ['name' => 'Shipping', 'color' => null]];
        $this->putJson('/settings/project_defaults', ['revision' => 1, 'columns' => $columns])->assertOk();

        $this->post('/projects', ['name' => 'Created in UI', 'status' => 'Idea'])->assertRedirect();
        OrbitServer::tool(ManageProjectTool::class, ['action' => 'create', 'name' => 'Created through MCP', 'status' => 'Idea'])->assertOk();
        $this->post('/projects/'.$existing->id.'/duplicate')->assertRedirect();

        $ui = Project::where('name', 'Created in UI')->sole();
        $mcp = Project::where('name', 'Created through MCP')->sole();
        $this->assertSame(['Ready', 'Shipping'], $ui->boardColumns->pluck('name')->all());
        $this->assertSame(['blue', null], $ui->boardColumns->pluck('color')->all());
        $this->assertSame(['Ready', 'Shipping'], $mcp->boardColumns->pluck('name')->all());
        $this->assertSame(['blue', null], $mcp->boardColumns->pluck('color')->all());
        $this->assertSame([], array_intersect($ui->boardColumns->modelKeys(), $mcp->boardColumns->modelKeys()));
        $this->assertSame($existingNames, $existing->fresh()->boardColumns->pluck('name')->all());
        $this->assertSame($existingNames, Project::where('name', $existing->name.' (copy)')->sole()->boardColumns->pluck('name')->all());
    }

    public function test_an_invalid_stored_board_template_cannot_partially_create_a_project(): void
    {
        app(WorkspacePreferences::class)->merge(['project_defaults' => ['columns' => []]]);

        $this->postJson('/projects', ['name' => 'Rejected', 'status' => 'Idea'])->assertUnprocessable();

        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('board_columns', 0);
    }

    public function test_last_project_resumes_its_overview_and_utility_pages_do_not_replace_it(): void
    {
        $project = Project::factory()->create();
        $this->putJson('/settings/general', ['revision' => 1, 'startup_destination' => 'last_project'])->assertOk();
        $this->get('/projects/'.$project->id)->assertOk();

        $this->get('/settings')->assertInertia(fn (Assert $page): Assert => $page
            ->where('statuses', ['Idea', 'In Progress', 'Live', 'Paused', 'Maintenance', 'Archived']));
        $this->get('/settings/backups')->assertOk();
        $this->get('/settings/connections')->assertOk();
        $this->get('/startup')->assertRedirect('/projects/'.$project->id);

        $this->assertSame($project->id, app(WorkspacePreferences::class)->get('startup.last_project_id'));
    }

    #[TestWith(['removed'])]
    #[TestWith(['archived'])]
    #[TestWith(['archived_timestamp'])]
    #[TestWith(['dashboard'])]
    public function test_startup_falls_back_to_dashboard_when_a_project_is_unavailable_or_not_selected(string $state): void
    {
        $project = Project::factory()->create();
        app(WorkspacePreferences::class)->merge(['general' => ['startup_destination' => $state === 'dashboard' ? 'dashboard' : 'last_project'], 'startup' => ['last_project_id' => $project->id]]);
        match ($state) {
            'removed' => $project->delete(),
            'archived' => $project->update(['status' => 'Archived']),
            'archived_timestamp' => $project->update(['archived_at' => now()]),
            default => null,
        };

        $this->get('/startup')->assertRedirect('/');
    }

    public function test_launch_at_login_reports_the_setting_confirmed_by_macos(): void
    {
        config(['nativephp-internal.running' => true]);
        App::shouldReceive('openAtLogin')->once()->with(true)->andReturn(true);
        App::shouldReceive('openAtLogin')->once()->withNoArgs()->andReturn(true);

        $this->putJson('/settings/general/login', ['enabled' => true])->assertOk()->assertExactJson(['enabled' => true]);

        $this->assertSame(['startup_destination' => 'dashboard'], app(WorkspacePreferences::class)->get('general'));
    }

    public function test_macos_login_confirmation_mismatch_never_reports_success(): void
    {
        config(['nativephp-internal.running' => true]);
        App::shouldReceive('openAtLogin')->once()->with(true)->andReturn(true);
        App::shouldReceive('openAtLogin')->once()->withNoArgs()->andReturn(false);

        $this->putJson('/settings/general/login', ['enabled' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('enabled')->assertJsonMissingPath('enabled');

        $this->assertSame(['startup_destination' => 'dashboard'], app(WorkspacePreferences::class)->get('general'));
    }

    public function test_native_login_failure_reports_an_error_without_saving_preferences(): void
    {
        config(['nativephp-internal.running' => true]);
        App::shouldReceive('openAtLogin')->once()->with(true)->andThrow(new RuntimeException('Private native error'));
        $snapshot = app(WorkspacePreferences::class)->snapshot();

        $this->putJson('/settings/general/login', ['enabled' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('enabled')->assertJsonMissingPath('enabled');

        $this->assertSame($snapshot, app(WorkspacePreferences::class)->snapshot());
    }

    public function test_native_theme_preview_and_login_changes_are_gated_in_browser_development(): void
    {
        System::shouldReceive('theme')->never();
        App::shouldReceive('openAtLogin')->never();

        $this->postJson('/settings/appearance/preview', ['theme' => 'dark'])->assertForbidden();
        $this->putJson('/settings/general/login', ['enabled' => true])->assertForbidden();
    }
}
