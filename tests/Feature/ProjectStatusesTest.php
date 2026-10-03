<?php

namespace Tests\Feature;

use App\Mcp\Servers\OrbitServer;
use App\Mcp\Tools\ManageProjectTool;
use App\Models\Project;
use App\WorkspacePreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProjectStatusesTest extends TestCase
{
    use RefreshDatabase;

    public function test_renaming_and_reordering_statuses_updates_projects_and_archive_restore_targets(): void
    {
        $idea = Project::factory()->create();
        $live = Project::factory()->create(['status' => 'Live']);
        $archived = Project::factory()->create(['status' => 'Archived', 'previous_status' => 'Idea', 'archived_at' => now()]);
        $statuses = [
            ['original' => 'Live', 'name' => 'Shipped'],
            ['original' => 'Idea', 'name' => '  Planning  '],
            ['original' => null, 'name' => 'Waiting'],
            ['original' => null, 'name' => 'Testing'],
        ];

        $this->putJson('/settings/project_statuses', ['revision' => 1, 'statuses' => $statuses])
            ->assertOk()->assertJsonPath('preferences.values.project_statuses.names', ['Shipped', 'Planning', 'Waiting', 'Testing'])
            ->assertJsonPath('preferences.values.project_statuses.colors', ['Shipped' => 'green', 'Planning' => 'purple', 'Waiting' => 'gray', 'Testing' => 'gray']);

        $this->assertDatabaseHas('projects', ['id' => $idea->id, 'status' => 'Planning', 'revision' => 2]);
        $this->assertDatabaseHas('projects', ['id' => $live->id, 'status' => 'Shipped', 'revision' => 2]);
        $this->assertDatabaseHas('projects', ['id' => $archived->id, 'status' => 'Archived', 'previous_status' => 'Planning', 'revision' => 2]);
        $this->assertSame($archived->archived_at->toISOString(), $archived->fresh()->archived_at->toISOString());
        $this->get('/projects/create')->assertInertia(fn (Assert $page): Assert => $page->where('statuses', ['Shipped', 'Planning', 'Waiting', 'Testing', 'Archived']));
        $this->get('/settings')->assertInertia(fn (Assert $page): Assert => $page->where('statuses.0', 'Shipped')
            ->where('statusUsage', fn ($usage): bool => collect($usage)->firstWhere('name', 'Planning')['count'] === 2));
        $this->get('/?status=Planning')->assertInertia(fn (Assert $page): Assert => $page->has('projects.data', 1)->where('projects.data.0.id', $idea->id));
        $this->putJson('/projects/'.$live->id, ['name' => 'Stale name', 'status' => 'Shipped', 'revision' => 1])->assertConflict();
        $this->assertDatabaseHas('projects', ['id' => $live->id, 'name' => $live->name]);
    }

    public function test_removing_a_used_status_requires_reassignment_and_keeps_archived_projects_archived(): void
    {
        $paused = Project::factory()->create(['status' => 'Paused']);
        $archived = Project::factory()->create(['status' => 'Archived', 'previous_status' => 'Paused', 'archived_at' => now()]);
        $statuses = [['original' => 'Idea', 'name' => 'Planning']];
        $snapshot = app(WorkspacePreferences::class)->snapshot();

        $this->putJson('/settings/project_statuses', ['revision' => 1, 'statuses' => $statuses])->assertUnprocessable()->assertJsonValidationErrors('replacements');

        $this->assertSame($snapshot, app(WorkspacePreferences::class)->snapshot());
        $this->assertDatabaseHas('projects', ['id' => $paused->id, 'status' => 'Paused', 'revision' => 1]);
        $this->putJson('/settings/project_statuses', ['revision' => 1, 'statuses' => $statuses, 'replacements' => [['original' => 'Paused', 'replacement' => 'Planning']]])->assertOk();
        $this->assertDatabaseHas('projects', ['id' => $paused->id, 'status' => 'Planning', 'revision' => 2]);
        $this->assertDatabaseHas('projects', ['id' => $archived->id, 'status' => 'Archived', 'previous_status' => 'Planning', 'revision' => 2]);
        $this->put('/projects/'.$archived->id, ['name' => $archived->name, 'status' => 'Planning', 'revision' => 2])->assertRedirect();
        $this->assertDatabaseHas('projects', ['id' => $archived->id, 'status' => 'Planning', 'previous_status' => null, 'archived_at' => null]);
    }

    public function test_an_archived_restore_target_alone_requires_a_replacement(): void
    {
        $project = Project::factory()->create(['status' => 'Archived', 'previous_status' => 'Maintenance', 'archived_at' => now()]);

        $this->putJson('/settings/project_statuses', ['revision' => 1, 'statuses' => [['original' => 'Idea', 'name' => 'Idea']]])
            ->assertUnprocessable()->assertJsonValidationErrors('replacements');

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'previous_status' => 'Maintenance', 'revision' => 1]);
        $this->assertSame(1, app(WorkspacePreferences::class)->snapshot()['revision']);
    }

    public function test_swapping_names_does_not_move_projects_twice(): void
    {
        $idea = Project::factory()->create();
        $live = Project::factory()->create(['status' => 'Live']);

        $this->putJson('/settings/project_statuses', ['revision' => 1, 'statuses' => [
            ['original' => 'Idea', 'name' => 'Live'], ['original' => 'Live', 'name' => 'Idea'],
        ]])->assertOk();

        $this->assertDatabaseHas('projects', ['id' => $idea->id, 'status' => 'Live', 'revision' => 2]);
        $this->assertDatabaseHas('projects', ['id' => $live->id, 'status' => 'Idea', 'revision' => 2]);
    }

    public function test_stale_settings_cannot_overwrite_statuses_or_projects(): void
    {
        $project = Project::factory()->create();
        $payload = ['revision' => 1, 'statuses' => [['original' => 'Idea', 'name' => 'Planning']]];
        $this->putJson('/settings/project_statuses', $payload)->assertOk();

        $this->putJson('/settings/project_statuses', $payload)->assertConflict();

        $this->assertSame(['Planning', 'Archived'], Project::statuses());
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'status' => 'Planning', 'revision' => 2]);
    }

    public function test_colors_follow_renames_and_color_only_edits_do_not_change_project_revisions(): void
    {
        $project = Project::factory()->create(['status' => 'Paused']);
        $this->putJson('/settings/project_statuses', ['revision' => 1, 'statuses' => [
            ['original' => 'Paused', 'name' => 'Waiting', 'color' => 'pink'],
            ['original' => 'Idea', 'name' => 'Planning', 'color' => 'blue'],
        ]])->assertOk()->assertJsonPath('preferences.values.project_statuses.colors', ['Waiting' => 'pink', 'Planning' => 'blue']);

        $this->putJson('/settings/project_statuses', ['revision' => 2, 'statuses' => [
            ['original' => 'Planning', 'name' => 'Planning', 'color' => 'orange'],
            ['original' => 'Waiting', 'name' => 'Waiting', 'color' => 'red'],
        ]])->assertOk();

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'status' => 'Waiting', 'revision' => 2]);
        $this->get('/')->assertInertia(fn (Assert $page): Assert => $page
            ->where('statuses', ['Planning', 'Waiting', 'Archived'])
            ->where('statusColors', ['Planning' => 'orange', 'Waiting' => 'red']));
    }

    #[DataProvider('invalidStatuses')]
    public function test_invalid_status_edits_leave_preferences_and_projects_intact(array $statuses, array $replacements = []): void
    {
        $project = Project::factory()->create();
        $snapshot = app(WorkspacePreferences::class)->snapshot();

        $this->putJson('/settings/project_statuses', ['revision' => 1, 'statuses' => $statuses, 'replacements' => $replacements])->assertUnprocessable();

        $this->assertSame($snapshot, app(WorkspacePreferences::class)->snapshot());
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'status' => 'Idea', 'revision' => 1]);
    }

    public static function invalidStatuses(): array
    {
        return [
            'empty list' => [[]],
            'blank name' => [[['original' => 'Idea', 'name' => ' ']]],
            'too long' => [[['original' => 'Idea', 'name' => str_repeat('a', 101)]]],
            'duplicates after trimming' => [[['original' => 'Idea', 'name' => 'Planning'], ['original' => 'Live', 'name' => ' planning ']]],
            'archive label' => [[['original' => 'Idea', 'name' => 'archived']]],
            'rename archive' => [[['original' => 'Archived', 'name' => 'Closed']]],
            'unknown original' => [[['original' => 'Missing', 'name' => 'Planning']]],
            'duplicate original' => [[['original' => 'Idea', 'name' => 'Planning'], ['original' => 'Idea', 'name' => 'Research']]],
            'removed replacement' => [[['original' => 'Live', 'name' => 'Live']], [['original' => 'Idea', 'replacement' => 'Paused']]],
            'archive replacement' => [[['original' => 'Live', 'name' => 'Live']], [['original' => 'Idea', 'replacement' => 'Archived']]],
            'invalid color' => [[['original' => 'Idea', 'name' => 'Idea', 'color' => 'url(https://example.com)']]],
            'empty color' => [[['original' => 'Idea', 'name' => 'Idea', 'color' => null]]],
        ];
    }

    public function test_custom_statuses_work_for_project_writes_mcp_and_scratchpad_actions(): void
    {
        app(WorkspacePreferences::class)->merge(['project_statuses' => ['names' => ['Planning', 'Review']]]);
        $this->post('/projects', ['name' => 'From UI', 'status' => 'Planning'])->assertRedirect();
        $project = Project::where('name', 'From UI')->sole();
        OrbitServer::tool(ManageProjectTool::class, ['action' => 'create', 'name' => 'From MCP', 'status' => 'Review'])->assertOk();
        $this->assertDatabaseHas('projects', ['name' => 'From MCP', 'status' => 'Review']);

        $this->postJson('/projects/'.$project->id.'/scratchpad/actions', ['revision' => 1, 'actions' => [
            ['type' => 'project_detail', 'field' => 'status', 'value' => 'Review'],
        ]])->assertOk();

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'status' => 'Review', 'revision' => 2]);
        $this->postJson('/projects', ['name' => 'Rejected', 'status' => 'Idea'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertDatabaseMissing('projects', ['name' => 'Rejected']);
        $this->postJson('/projects/'.$project->id.'/scratchpad/actions', ['revision' => 2, 'actions' => [
            ['type' => 'project_detail', 'field' => 'status', 'value' => 'Idea'],
        ]])->assertUnprocessable();
        $this->assertSame('Review', $project->fresh()->status);
    }

    public function test_numeric_status_names_can_be_filtered(): void
    {
        app(WorkspacePreferences::class)->merge(['project_statuses' => ['names' => ['0', '1']]]);
        $project = Project::factory()->create(['status' => '0']);
        Project::factory()->create(['status' => '1']);

        $this->get('/?status=0')->assertInertia(fn (Assert $page): Assert => $page->has('projects.data', 1)->where('projects.data.0.id', $project->id));
    }
}
