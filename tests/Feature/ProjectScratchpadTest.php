<?php

namespace Tests\Feature;

use App\Actions\ProtectCredential;
use App\Models\Project;
use App\WorkspacePreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\StructuredAnonymousAgent;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ProjectScratchpadTest extends TestCase
{
    use RefreshDatabase;

    public function test_scratchpad_saves_on_its_project_without_leaking_to_the_project_list(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->create();
        $url = '/projects/'.$project->id;

        $this->put($url.'/scratchpad', ['scratchpad' => "Call Sam\nDraft release tasks", 'revision' => 1])->assertRedirect($url);

        $this->assertSame("Call Sam\nDraft release tasks", $project->fresh()->scratchpad);
        $this->assertNull($other->fresh()->scratchpad);
        $this->assertSame(2, $project->fresh()->revision);
        $this->get($url)->assertInertia(fn (Assert $page): Assert => $page->where('selectedProject.scratchpad', "Call Sam\nDraft release tasks"));
        $this->get('/')->assertInertia(fn (Assert $page): Assert => $page->missing('projects.data.0.scratchpad'));

        $this->put($url, ['name' => $project->name, 'status' => 'Live', 'revision' => 2])->assertRedirect($url);
        $this->assertSame("Call Sam\nDraft release tasks", $project->fresh()->scratchpad);
        $this->put($url.'/scratchpad', ['scratchpad' => null, 'revision' => 3])->assertRedirect($url);
        $this->assertNull($project->fresh()->scratchpad);
    }

    public function test_json_scratchpad_save_returns_new_revision_without_redirecting(): void
    {
        $project = Project::factory()->create();

        $response = $this->withHeader('X-Inertia-Version', 'stale')
            ->putJson('/projects/'.$project->id.'/scratchpad', ['scratchpad' => 'Draft release tasks', 'revision' => 1])
            ->assertOk();

        $response->assertExactJson(['revision' => 2, 'updated_at' => $project->fresh()->updated_at->toJSON()]);

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'scratchpad' => 'Draft release tasks', 'revision' => 2]);
    }

    public function test_stale_and_invalid_scratchpad_edits_leave_saved_notes_unchanged(): void
    {
        $project = Project::factory()->create(['scratchpad' => 'Keep this']);
        $url = '/projects/'.$project->id.'/scratchpad';

        $this->putJson($url, ['scratchpad' => 'Stale', 'revision' => 2])->assertConflict();
        $this->putJson($url, ['revision' => 1])->assertUnprocessable()->assertJsonValidationErrors('scratchpad');
        $this->putJson($url, ['scratchpad' => str_repeat('x', 50001), 'revision' => 1])->assertUnprocessable()->assertJsonValidationErrors('scratchpad');
        $this->from('/projects/'.$project->id)->withHeader('X-Inertia', 'true')->put($url, ['scratchpad' => 'Still stale', 'revision' => 2])
            ->assertRedirect('/projects/'.$project->id)->assertSessionHasErrors('revision');

        $this->assertSame('Keep this', $project->fresh()->scratchpad);
        $this->assertSame(1, $project->fresh()->revision);
    }

    public function test_ai_preview_returns_typed_actions_without_creating_records(): void
    {
        $this->configureAi();
        StructuredAnonymousAgent::fake([['actions' => [
            ['type' => 'link', 'label' => '  Launch site  ', 'url' => 'https://example.com', 'description' => 'Reference'],
            ['type' => 'document', 'title' => '  Release plan  ', 'body' => 'Draft launch notes'],
            ['type' => 'task', 'title' => '  Ask Sam for dates  '],
            ['type' => 'project_detail', 'field' => 'tag', 'value' => '  Launch  '],
        ]]])->preventStrayPrompts();
        $notes = "https://example.com\nDraft launch notes\nAsk Sam for dates\nTag project launch";
        $project = Project::factory()->create(['scratchpad' => $notes]);

        $this->postJson('/projects/'.$project->id.'/scratchpad/actions/preview', ['revision' => 1])
            ->assertOk()->assertExactJson(['actions' => [
                ['type' => 'link', 'label' => 'Launch site', 'url' => 'https://example.com', 'description' => 'Reference'],
                ['type' => 'document', 'title' => 'Release plan', 'body' => 'Draft launch notes'],
                ['type' => 'task', 'title' => 'Ask Sam for dates', 'column_id' => null],
                ['type' => 'project_detail', 'field' => 'tag', 'value' => 'Launch'],
            ], 'statuses' => Project::STATUSES]);

        StructuredAnonymousAgent::assertPrompted($notes);
        $this->assertSame(1, $project->fresh()->revision);
        $this->assertSame($notes, $project->fresh()->scratchpad);
        $this->assertDatabaseCount('project_links', 0);
        $this->assertDatabaseCount('project_documents', 0);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_ai_preview_supplies_board_columns_and_preserves_suggested_task_destinations(): void
    {
        $this->configureAi();
        $notes = "Not overly sure on Project overview, Secrets, or Dependencies...\nSettings are a bit ugly\n\nTodo\nLicense system\nAPI functionality (keeping local first)\nMobile app";
        $project = Project::factory()->create(['scratchpad' => $notes]);
        $columns = $project->boardColumns()->get();
        $todo = $columns->firstWhere('name', 'To Do');
        $actions = [
            ['type' => 'task', 'title' => 'Improve settings', 'column_id' => null],
            ['type' => 'task', 'title' => 'License system', 'column_id' => $todo->id],
            ['type' => 'task', 'title' => 'API functionality (keeping local first)', 'column_id' => $todo->id],
            ['type' => 'task', 'title' => 'Mobile app', 'column_id' => $todo->id],
        ];
        StructuredAnonymousAgent::fake([['actions' => $actions]])->preventStrayPrompts();

        $this->postJson('/projects/'.$project->id.'/scratchpad/actions/preview', ['revision' => 1])
            ->assertOk()->assertExactJson(['actions' => $actions, 'statuses' => Project::STATUSES]);

        StructuredAnonymousAgent::assertPrompted(function (AgentPrompt $prompt) use ($notes, $columns): bool {
            $context = json_decode(explode('Project context: ', $prompt->agent->instructions(), 2)[1], true, flags: JSON_THROW_ON_ERROR);
            $schema = $prompt->agent->schema(new JsonSchemaTypeFactory)['actions']->toArray();
            $this->assertSame([$columns[0]->id, $columns[1]->id, $columns[2]->id, $columns[3]->id, null], $schema['items']['properties']['column_id']['enum']);

            return $prompt->prompt === $notes && $context['board_columns'] === [
                ['id' => $columns[0]->id, 'name' => 'Backlog'],
                ['id' => $columns[1]->id, 'name' => 'To Do'],
                ['id' => $columns[2]->id, 'name' => 'In Progress'],
                ['id' => $columns[3]->id, 'name' => 'Done'],
            ];
        });
        $this->assertSame($notes, $project->fresh()->scratchpad);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_ai_preview_reuses_results_across_revisions_until_the_cache_expires(): void
    {
        $this->configureAi();
        $this->freezeTime();
        $actions = [['type' => 'task', 'title' => 'Plan launch', 'column_id' => null]];
        StructuredAnonymousAgent::fake([['actions' => $actions], ['actions' => $actions]])->preventStrayPrompts();
        $project = Project::factory()->create(['scratchpad' => 'Plan launch']);
        $url = '/projects/'.$project->id.'/scratchpad/actions/preview';

        $this->postJson($url, ['revision' => 1])->assertOk()->assertJsonPath('actions', $actions);
        $project->forceFill(['revision' => 2])->save();
        $this->postJson($url, ['revision' => 1])->assertConflict();
        $this->postJson($url, ['revision' => 2])->assertOk()->assertJsonPath('actions', $actions);
        StructuredAnonymousAgent::assertPromptedTimes(1);

        $this->travel(8)->days();
        $this->postJson($url, ['revision' => 2])->assertOk()->assertJsonPath('actions', $actions);

        StructuredAnonymousAgent::assertPromptedTimes(2);
        $this->assertSame('Plan launch', $project->fresh()->scratchpad);
        $this->assertDatabaseCount('tasks', 0);
    }

    #[TestWith(['scratchpad'])]
    #[TestWith(['project_context'])]
    #[TestWith(['board_columns'])]
    #[TestWith(['model'])]
    #[TestWith(['provider'])]
    public function test_ai_preview_regenerates_when_an_input_changes(string $changedInput): void
    {
        $this->configureAi();
        $actions = [['type' => 'task', 'title' => 'Plan launch', 'column_id' => null]];
        StructuredAnonymousAgent::fake([['actions' => $actions], ['actions' => $actions]])->preventStrayPrompts();
        $project = Project::factory()->create(['scratchpad' => 'Plan launch']);
        $url = '/projects/'.$project->id.'/scratchpad/actions/preview';
        $this->postJson($url, ['revision' => 1])->assertOk();

        match ($changedInput) {
            'scratchpad' => $project->update(['scratchpad' => 'Plan a different launch']),
            'project_context' => $project->update(['description' => 'Changed project context']),
            'board_columns' => $project->boardColumns()->first()->update(['name' => 'Ideas']),
            'model' => app(WorkspacePreferences::class)->merge(['ai' => ['model' => 'different-model']]),
            'provider' => app(WorkspacePreferences::class)->merge(['ai' => ['provider' => 'anthropic']]),
        };
        $this->postJson($url, ['revision' => 1])->assertOk()->assertJsonPath('actions', $actions);

        StructuredAnonymousAgent::assertPromptedTimes(2);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_invalid_ai_results_are_not_cached(): void
    {
        $this->configureAi();
        $actions = [['type' => 'task', 'title' => 'Plan launch', 'column_id' => null]];
        StructuredAnonymousAgent::fake([['actions' => [['type' => 'task', 'title' => '']]], ['actions' => $actions]])->preventStrayPrompts();
        $project = Project::factory()->create(['scratchpad' => 'Plan launch']);
        $url = '/projects/'.$project->id.'/scratchpad/actions/preview';

        $this->postJson($url, ['revision' => 1])->assertUnprocessable()->assertJsonValidationErrors('scratchpad');
        $this->postJson($url, ['revision' => 1])->assertOk()->assertJsonPath('actions', $actions);

        StructuredAnonymousAgent::assertPromptedTimes(2);
        $this->assertSame('Plan launch', $project->fresh()->scratchpad);
    }

    public function test_bare_url_previews_as_a_link_without_an_ai_key_or_call(): void
    {
        app(WorkspacePreferences::class)->merge(['ai' => ['provider' => null, 'model' => null, 'credential' => null]]);
        StructuredAnonymousAgent::fake()->preventStrayPrompts();
        $project = Project::factory()->create(['scratchpad' => 'https://usebuff.app/']);

        $this->postJson('/projects/'.$project->id.'/scratchpad/actions/preview', ['revision' => 1])
            ->assertOk()->assertExactJson(['actions' => [[
                'type' => 'link', 'label' => 'usebuff.app', 'url' => 'https://usebuff.app/', 'description' => '',
            ]], 'statuses' => Project::STATUSES]);

        StructuredAnonymousAgent::assertNeverPrompted();
        $this->assertSame('https://usebuff.app/', $project->fresh()->scratchpad);
        $this->assertDatabaseCount('project_links', 0);
    }

    public function test_ai_preview_drops_task_suggestions_when_the_project_has_no_board(): void
    {
        $this->configureAi();
        StructuredAnonymousAgent::fake([['actions' => [
            ['type' => 'task', 'title' => 'Review launch'],
            ['type' => 'link', 'label' => 'Launch', 'url' => 'https://example.com'],
        ]]])->preventStrayPrompts();
        $project = Project::factory()->create(['scratchpad' => 'Save https://example.com and review launch']);
        $project->boardColumns()->delete();

        $this->postJson('/projects/'.$project->id.'/scratchpad/actions/preview', ['revision' => 1])
            ->assertOk()->assertExactJson(['actions' => [[
                'type' => 'link', 'label' => 'Launch', 'url' => 'https://example.com', 'description' => '',
            ]], 'statuses' => Project::STATUSES]);

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_ai_preview_with_no_actions_keeps_notes(): void
    {
        $this->configureAi();
        StructuredAnonymousAgent::fake([['actions' => []]])->preventStrayPrompts();
        $project = Project::factory()->create(['scratchpad' => 'General background context']);

        $this->postJson('/projects/'.$project->id.'/scratchpad/actions/preview', ['revision' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('scratchpad');
        $this->postJson('/projects/'.$project->id.'/scratchpad/actions/preview', ['revision' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('scratchpad');

        StructuredAnonymousAgent::assertPrompted('General background context');
        StructuredAnonymousAgent::assertPromptedTimes(1);
        $this->assertSame('General background context', $project->fresh()->scratchpad);
    }

    public function test_accepted_actions_create_mixed_project_content_and_clear_scratchpad_atomically(): void
    {
        $project = Project::factory()->create(['status' => 'Live', 'description' => 'Old description', 'scratchpad' => 'Make these changes']);
        $column = $project->boardColumns()->where('name', 'To Do')->firstOrFail();

        $this->postJson('/projects/'.$project->id.'/scratchpad/actions', ['revision' => 1, 'actions' => [
            ['type' => 'link', 'label' => 'UseBuff', 'url' => 'https://usebuff.app/', 'description' => 'Check the site'],
            ['type' => 'document', 'title' => 'Release notes', 'body' => "## Launch\nReady to ship"],
            ['type' => 'task', 'title' => 'Ask Sam for dates', 'column_id' => $column->id],
            ['type' => 'project_detail', 'field' => 'name', 'value' => 'Updated project'],
            ['type' => 'project_detail', 'field' => 'description', 'value' => ''],
            ['type' => 'project_detail', 'field' => 'status', 'value' => 'Archived'],
            ['type' => 'project_detail', 'field' => 'tag', 'value' => ' Launch '],
        ]])->assertOk()->assertExactJson(['revision' => 2]);

        $this->assertDatabaseHas('project_links', ['project_id' => $project->id, 'label' => 'UseBuff', 'url' => 'https://usebuff.app/', 'description' => 'Check the site']);
        $this->assertDatabaseHas('project_documents', ['project_id' => $project->id, 'title' => 'Release notes', 'body' => "## Launch\nReady to ship"]);
        $this->assertDatabaseHas('tasks', ['board_column_id' => $column->id, 'title' => 'Ask Sam for dates']);
        $this->assertSame(['launch'], $project->tags()->pluck('name')->all());
        $current = $project->fresh();
        $this->assertSame('Updated project', $current->name);
        $this->assertSame('', $current->description);
        $this->assertSame('Archived', $current->status);
        $this->assertSame('Live', $current->previous_status);
        $this->assertNotNull($current->archived_at);
        $this->assertNull($current->scratchpad);
        $this->assertSame(2, $current->revision);
    }

    public function test_invalid_or_stale_accepted_actions_leave_notes_and_project_unchanged(): void
    {
        $project = Project::factory()->create(['scratchpad' => 'Keep these notes']);
        $project->links()->create(['label' => 'Existing', 'url' => 'https://usebuff.app/', 'position' => 0]);
        $column = $project->boardColumns()->first();
        $column->tasks()->create(['title' => 'Already planned', 'position' => 0]);
        $url = '/projects/'.$project->id.'/scratchpad/actions';

        $this->postJson($url, ['revision' => 2, 'actions' => [['type' => 'document', 'title' => 'Plan']]])->assertConflict();
        $this->postJson($url, ['revision' => 1, 'actions' => [
            ['type' => 'document', 'title' => 'Plan'],
            ['type' => 'link', 'label' => 'Duplicate', 'url' => 'https://usebuff.app/'],
        ]])->assertUnprocessable()->assertJsonValidationErrors('actions');
        $this->postJson($url, ['revision' => 1, 'actions' => [['type' => 'link', 'label' => 'Unsafe', 'url' => 'javascript:alert(1)']]])
            ->assertUnprocessable()->assertJsonValidationErrors('actions.0.url');
        $this->postJson($url, ['revision' => 1, 'actions' => [
            ['type' => 'project_detail', 'field' => 'name', 'value' => 'First'],
            ['type' => 'project_detail', 'field' => 'name', 'value' => 'Second'],
        ]])->assertUnprocessable()->assertJsonValidationErrors('actions');
        $this->postJson($url, ['revision' => 1, 'actions' => [['type' => 'task', 'title' => 'Already planned', 'column_id' => $column->id]]])
            ->assertUnprocessable()->assertJsonValidationErrors('actions');
        $this->postJson($url, ['revision' => 1, 'actions' => [['type' => 'task', 'title' => 'Choose a destination']]])
            ->assertUnprocessable()->assertInvalid(['actions' => 'Choose a board list belonging to this project.']);
        $otherColumn = Project::factory()->create()->boardColumns()->first();
        $this->postJson($url, ['revision' => 1, 'actions' => [['type' => 'task', 'title' => 'Wrong project', 'column_id' => $otherColumn->id]]])
            ->assertUnprocessable()->assertInvalid(['actions' => 'Choose a board list belonging to this project.']);
        $this->postJson($url, ['revision' => 1, 'actions' => [['type' => 'project_detail', 'field' => 'status', 'value' => 'Unknown']]])
            ->assertUnprocessable()->assertJsonValidationErrors('actions');

        $this->assertSame('Keep these notes', $project->fresh()->scratchpad);
        $this->assertSame(1, $project->fresh()->revision);
        $this->assertSame(1, $project->links()->count());
        $this->assertDatabaseCount('project_documents', 0);
        $this->assertDatabaseCount('tasks', 1);
    }

    public function test_ai_preview_requires_current_notes_and_saved_ai_connection(): void
    {
        $project = Project::factory()->create();
        $url = '/projects/'.$project->id.'/scratchpad/actions/preview';

        $this->postJson($url, ['revision' => 1])->assertUnprocessable()->assertJsonValidationErrors('scratchpad');
        $project->update(['scratchpad' => 'Make a release checklist']);
        $this->postJson($url, ['revision' => 2])->assertConflict();
        app(WorkspacePreferences::class)->merge(['ai' => ['provider' => null, 'model' => null, 'credential' => null]]);
        $this->postJson($url, ['revision' => 1])->assertUnprocessable()->assertJsonValidationErrors('scratchpad');

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_ai_provider_failure_keeps_the_scratchpad_and_board_unchanged(): void
    {
        $this->configureAi();
        $attempts = 0;
        StructuredAnonymousAgent::fake(function () use (&$attempts): array {
            if ($attempts++ === 0) {
                throw new AiException('Provider failed');
            }

            return ['actions' => [['type' => 'task', 'title' => 'Ship the release']]];
        })->preventStrayPrompts();
        $project = Project::factory()->create(['scratchpad' => 'Ship the release']);

        $this->postJson('/projects/'.$project->id.'/scratchpad/actions/preview', ['revision' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('scratchpad');
        $this->postJson('/projects/'.$project->id.'/scratchpad/actions/preview', ['revision' => 1])->assertOk();

        StructuredAnonymousAgent::assertPromptedTimes(2);
        $this->assertSame('Ship the release', $project->fresh()->scratchpad);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_scratchpad_survives_duplication(): void
    {
        $project = Project::factory()->create(['scratchpad' => 'Turn these notes into tasks']);

        $this->post('/projects/'.$project->id.'/duplicate')->assertRedirect();

        $this->assertSame('Turn these notes into tasks', $project->fresh()->scratchpad);
        $this->assertSame('Turn these notes into tasks', Project::whereKeyNot($project->id)->sole()->scratchpad);
    }

    private function configureAi(): void
    {
        $this->mock(ProtectCredential::class, function ($mock): void {
            $mock->shouldReceive('decrypt')->with('encrypted-test-key')->andReturn('test-key');
        });
        app(WorkspacePreferences::class)->merge(['ai' => ['provider' => 'openai', 'model' => 'test-model', 'credential' => 'encrypted-test-key']]);
    }
}
